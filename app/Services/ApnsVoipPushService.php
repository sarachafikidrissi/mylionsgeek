<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends Apple PushKit VoIP notifications for incoming calls (iOS cold start / CallKit).
 *
 * Requires APNs Auth Key (.p8) from Apple Developer → Keys → Apple Push Notifications.
 * Env:
 *   APNS_KEY_ID, APNS_TEAM_ID, APNS_BUNDLE_ID
 *   APNS_KEY_PATH (absolute/.relative path to .p8) OR APNS_KEY_CONTENTS
 *   APNS_PRODUCTION=true|false (false = sandbox)
 */
class ApnsVoipPushService
{
    public function isConfigured(): bool
    {
        $keyId = (string) config('services.apns.key_id');
        $teamId = (string) config('services.apns.team_id');
        $bundleId = (string) config('services.apns.bundle_id');

        return $keyId !== '' && $teamId !== '' && $bundleId !== '' && $this->resolveKeyContents() !== null;
    }

    /**
     * @param  array<string, mixed>  $callPayload
     */
    public function sendIncomingCall(User $user, array $callPayload): bool
    {
        $token = $user->apns_voip_token ?? null;
        if (! is_string($token) || $token === '') {
            return false;
        }

        if (! $this->isConfigured()) {
            Log::warning('APNs VoIP not configured; skipping VoIP push', [
                'user_id' => $user->id,
            ]);

            return false;
        }

        $uuid = (string) ($callPayload['uuid'] ?? Str::uuid());
        $callerName = (string) ($callPayload['caller_name'] ?? 'LionsGeek user');
        $callId = $callPayload['call_id'] ?? null;
        $callType = (string) ($callPayload['call_type'] ?? 'audio');

        $body = [
            'uuid' => $uuid,
            'callerName' => $callerName,
            'caller_name' => $callerName,
            'handle' => (string) ($callPayload['handle'] ?? $callId ?? $uuid),
            'call_id' => $callId,
            'call_type' => $callType,
            'type' => (string) ($callPayload['type'] ?? 'incoming_call'),
            'channel_name' => $callPayload['channel_name'] ?? null,
            'caller_id' => $callPayload['caller_id'] ?? null,
        ];
        if (! empty($callPayload['cancelled'])) {
            $body['cancelled'] = '1';
            $body['type'] = 'call_cancelled';
        }

        return $this->send($token, $body);
    }

    /**
     * End a CallKit ringing call (same UUID as the incoming VoIP).
     */
    public function sendHangup(User $user, string $uuid, mixed $callId): bool
    {
        return $this->sendIncomingCall($user, [
            'uuid' => $uuid,
            'call_id' => $callId,
            'type' => 'call_cancelled',
            'cancelled' => '1',
            'caller_name' => 'LionsGeek',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(string $deviceToken, array $payload): bool
    {
        $jwt = $this->makeJwt();
        if (! $jwt) {
            return false;
        }

        $bundleId = rtrim((string) config('services.apns.bundle_id'), '.');
        $topic = $bundleId.'.voip';
        $host = config('services.apns.production')
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';

        $url = $host.'/3/device/'.$deviceToken;
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_HTTPHEADER => [
                'authorization: bearer '.$jwt,
                'apns-topic: '.$topic,
                'apns-push-type: voip',
                'apns-priority: 10',
                'apns-expiration: '.(string) (time() + 45),
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            Log::warning('APNs VoIP push failed', [
                'status' => $status,
                'error' => $error,
                'response' => is_string($response) ? substr($response, 0, 500) : null,
            ]);

            return false;
        }

        Log::info('APNs VoIP push sent', ['status' => $status]);

        return true;
    }

    private function resolveKeyContents(): ?string
    {
        $inline = config('services.apns.key_contents');
        if (is_string($inline) && trim($inline) !== '') {
            return str_replace('\\n', "\n", $inline);
        }

        $path = config('services.apns.key_path');
        if (! is_string($path) || $path === '') {
            return null;
        }

        if (! is_file($path)) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    private function makeJwt(): ?string
    {
        $keyId = (string) config('services.apns.key_id');
        $teamId = (string) config('services.apns.team_id');
        $pem = $this->resolveKeyContents();
        if ($pem === null) {
            return null;
        }

        $header = $this->b64url(json_encode(['alg' => 'ES256', 'kid' => $keyId], JSON_UNESCAPED_SLASHES));
        $claims = $this->b64url(json_encode(['iss' => $teamId, 'iat' => time()], JSON_UNESCAPED_SLASHES));
        $signingInput = $header.'.'.$claims;

        $privateKey = openssl_pkey_get_private($pem);
        if ($privateKey === false) {
            Log::error('APNs VoIP: invalid .p8 private key');

            return null;
        }

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            Log::error('APNs VoIP: openssl_sign failed');

            return null;
        }

        // Convert DER ECDSA signature to IEEE P1363 (R||S) expected by APNs.
        $signature = $this->derToJose($signature);
        if ($signature === null) {
            return null;
        }

        return $signingInput.'.'.$this->b64url($signature);
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Convert OpenSSL ASN.1 DER ECDSA signature to raw R||S (64 bytes for P-256).
     */
    private function derToJose(string $der): ?string
    {
        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") {
            return null;
        }

        $length = ord($der[$offset++]);
        if ($length & 0x80) {
            $nbytes = $length & 0x7F;
            $length = 0;
            for ($i = 0; $i < $nbytes; $i++) {
                $length = ($length << 8) | ord($der[$offset++]);
            }
        }

        if (($der[$offset++] ?? '') !== "\x02") {
            return null;
        }
        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        if (($der[$offset++] ?? '') !== "\x02") {
            return null;
        }
        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

        if (strlen($r) !== 32 || strlen($s) !== 32) {
            return null;
        }

        return $r.$s;
    }
}
