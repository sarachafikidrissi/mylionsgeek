<?php

namespace App\Services;

use Ably\AblyRest;
use App\Models\Call;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CallService
{
    public function __construct(
        protected AgoraTokenService $agoraTokenService,
        protected ExpoPushNotificationService $expoPush,
        protected ApnsVoipPushService $apnsVoipPush
    ) {
    }

    private function publishToUserChannel(int $userId, string $eventName, array $payload): void
    {
        try {
            $ablyKey = config('services.ably.key');
            if (! $ablyKey) {
                Log::warning('Ably not configured, skipping call broadcast');

                return;
            }
            $ably = new AblyRest($ablyKey);
            $channel = $ably->channels->get('call:user:'.$userId);
            $channel->publish($eventName, $payload);
        } catch (\Throwable $e) {
            Log::error('CallService Ably publish failed: '.$e->getMessage());
        }
    }

    private function assertCanCommunicate(User $caller, User $callee): void
    {
        if (! Schema::hasTable('user_blocks')) {
            return;
        }

        $blocked = UserBlock::query()
            ->where(function ($q) use ($caller, $callee) {
                $q->where('blocker_id', $caller->id)->where('blocked_id', $callee->id);
            })
            ->orWhere(function ($q) use ($caller, $callee) {
                $q->where('blocker_id', $callee->id)->where('blocked_id', $caller->id);
            })
            ->exists();

        if ($blocked) {
            throw new \InvalidArgumentException('You cannot call this user.');
        }
    }

    private function assertNoActiveCall(User $user): void
    {
        // Clear abandoned rows first so a crashed client cannot block new calls forever.
        $this->cleanupStaleCalls();

        $busy = Call::query()
            ->forUser((int) $user->id)
            ->whereIn('status', [Call::STATUS_RINGING, Call::STATUS_ACCEPTED])
            ->exists();

        if ($busy) {
            throw new \InvalidArgumentException('You already have an active call.');
        }
    }

    private function serializeParticipant(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->image ?? null,
            'image' => $user->image ?? null,
        ];
    }

    /**
     * @return array{call: Call, channel_name: string, token: string, app_id: string|null}
     */
    public function initiate(User $caller, int $calleeId, string $type = Call::TYPE_AUDIO): array
    {
        if ($caller->id === $calleeId) {
            throw new \InvalidArgumentException('Cannot call yourself.');
        }

        if (! in_array($type, [Call::TYPE_AUDIO, Call::TYPE_VIDEO], true)) {
            throw new \InvalidArgumentException('Invalid call type.');
        }

        $callee = User::find($calleeId);
        if (! $callee) {
            throw new \InvalidArgumentException('Callee not found.');
        }

        $this->assertCanCommunicate($caller, $callee);
        $this->assertNoActiveCall($caller);
        $this->assertNoActiveCall($callee);

        $channelName = 'call_'.uniqid('', true);

        $call = Call::create([
            'caller_id' => $caller->id,
            'callee_id' => $callee->id,
            'channel_name' => $channelName,
            'type' => $type,
            'status' => Call::STATUS_RINGING,
            'started_at' => now(),
        ]);

        $callerToken = $this->agoraTokenService->generateRtcToken($channelName, $caller->id);
        if (! $callerToken) {
            $call->delete();
            throw new \RuntimeException('Failed to generate Agora token.');
        }

        $voipUuid = (string) \Illuminate\Support\Str::uuid();
        if (Schema::hasColumn('calls', 'voip_uuid')) {
            $call->voip_uuid = $voipUuid;
            $call->save();
        }

        $payload = [
            'call_id' => $call->id,
            'channel_name' => $channelName,
            'call_type' => $type,
            'type' => $type,
            'uuid' => $voipUuid,
            'caller' => $this->serializeParticipant($caller),
        ];

        $this->publishToUserChannel($callee->id, 'incoming-call', $payload);

        $pushData = [
            'type' => 'incoming_call',
            'call_id' => $call->id,
            'call_type' => $type,
            'channel_name' => $channelName,
            'caller_id' => $caller->id,
            'caller_name' => $caller->name,
            'uuid' => $voipUuid,
        ];

        // iOS cold-start: PushKit VoIP → CallKit. No Expo banner when VoIP actually sent.
        $sentVoip = $this->apnsVoipPush->sendIncomingCall($callee, array_merge($pushData, [
            'handle' => (string) $caller->id,
        ]));

        if (! $sentVoip) {
            // Android, or iOS without a working VoIP push: data-only wake, no notification banner.
            $this->expoPush->sendDataOnly($callee, $pushData);
        }

        return [
            'call' => $call,
            'channel_name' => $channelName,
            'token' => $callerToken,
            'app_id' => config('services.agora.app_id'),
        ];
    }

    /**
     * @return array{call: Call, channel_name: string, token: string, app_id: string|null}
     */
    public function accept(int $callId, User $acceptor): array
    {
        // Token before status flip so Agora failure cannot leave a stuck "accepted" row.
        $calleeToken = null;
        $channelName = null;
        $callType = null;

        $call = DB::transaction(function () use ($callId, $acceptor, &$calleeToken, &$channelName, &$callType) {
            $call = Call::query()->whereKey($callId)->lockForUpdate()->firstOrFail();

            if ((int) $call->callee_id !== (int) $acceptor->id) {
                throw new \InvalidArgumentException('Only the callee can accept this call.');
            }
            if (! $call->isRinging()) {
                throw new \InvalidArgumentException('Call is no longer ringing.');
            }

            $calleeToken = $this->agoraTokenService->generateRtcToken($call->channel_name, $call->callee_id);
            if (! $calleeToken) {
                throw new \RuntimeException('Failed to generate Agora token.');
            }

            $now = now();
            $updated = Call::query()
                ->whereKey($call->id)
                ->where('status', Call::STATUS_RINGING)
                ->update([
                    'status' => Call::STATUS_ACCEPTED,
                    'answered_at' => $now,
                    'started_at' => $call->started_at ?: $now,
                    'updated_at' => $now,
                ]);

            if ($updated !== 1) {
                throw new \InvalidArgumentException('Call is no longer ringing.');
            }

            $call->refresh();
            $call->load(['caller', 'callee']);
            $channelName = $call->channel_name;
            $callType = $call->type;

            return $call;
        });

        $this->publishToUserChannel($call->caller_id, 'call-accepted', [
            'call_id' => $call->id,
            'channel_name' => $channelName,
            'call_type' => $callType,
        ]);

        return [
            'call' => $call,
            'channel_name' => $channelName,
            'token' => $calleeToken,
            'app_id' => config('services.agora.app_id'),
        ];
    }

    public function reject(int $callId, User $rejector): void
    {
        $call = DB::transaction(function () use ($callId, $rejector) {
            $call = Call::query()->whereKey($callId)->lockForUpdate()->firstOrFail();

            if ((int) $call->callee_id !== (int) $rejector->id) {
                throw new \InvalidArgumentException('Only the callee can reject this call.');
            }
            if (! $call->isRinging()) {
                throw new \InvalidArgumentException('Call is no longer ringing.');
            }

            $updated = Call::query()
                ->whereKey($call->id)
                ->where('status', Call::STATUS_RINGING)
                ->update([
                    'status' => Call::STATUS_REJECTED,
                    'ended_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new \InvalidArgumentException('Call is no longer ringing.');
            }

            $call->refresh();

            return $call;
        });

        $this->publishToUserChannel($call->caller_id, 'call-rejected', [
            'call_id' => $call->id,
            'uuid' => $call->voip_uuid,
        ]);
        // Stop CallKit / CallKeep on other callee devices still ringing.
        $this->signalCalleeStopRinging($call);
    }

    public function cancel(int $callId, User $caller): void
    {
        $call = DB::transaction(function () use ($callId, $caller) {
            $call = Call::query()->whereKey($callId)->lockForUpdate()->firstOrFail();

            if ((int) $call->caller_id !== (int) $caller->id) {
                throw new \InvalidArgumentException('Only the caller can cancel this call.');
            }
            if (! $call->isRinging()) {
                throw new \InvalidArgumentException('Call is no longer ringing.');
            }

            $updated = Call::query()
                ->whereKey($call->id)
                ->where('status', Call::STATUS_RINGING)
                ->update([
                    'status' => Call::STATUS_CANCELLED,
                    'ended_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated !== 1) {
                throw new \InvalidArgumentException('Call is no longer ringing.');
            }

            $call->refresh();

            return $call;
        });

        $this->publishToUserChannel($call->callee_id, 'call-cancelled', [
            'call_id' => $call->id,
            'uuid' => $call->voip_uuid,
        ]);
        // Keep legacy event name for older clients.
        $this->publishToUserChannel($call->callee_id, 'call-ended', [
            'call_id' => $call->id,
            'uuid' => $call->voip_uuid,
        ]);
        $this->signalCalleeStopRinging($call);
    }

    /**
     * Stop CallKit / CallKeep on the callee as soon as the caller hangs up.
     */
    private function signalCalleeStopRinging(Call $call): void
    {
        $callee = User::query()->find($call->callee_id);
        if (! $callee) {
            return;
        }

        $uuid = is_string($call->voip_uuid) ? $call->voip_uuid : null;
        $data = [
            'type' => 'call_cancelled',
            'call_id' => $call->id,
            'uuid' => $uuid,
            'cancelled' => '1',
        ];

        if ($uuid) {
            $this->apnsVoipPush->sendHangup($callee, $uuid, $call->id);
        }
        $this->expoPush->sendDataOnly($callee, $data);
    }

    public function end(int $callId, User $user): void
    {
        $existing = Call::query()->findOrFail($callId);
        if (! $existing->isParticipant((int) $user->id)) {
            throw new \InvalidArgumentException('You are not a participant of this call.');
        }

        // Caller hanging up while still ringing = cancel.
        if ($existing->isRinging() && (int) $existing->caller_id === (int) $user->id) {
            $this->cancel($callId, $user);

            return;
        }

        // Callee hanging up while ringing = reject.
        if ($existing->isRinging() && (int) $existing->callee_id === (int) $user->id) {
            $this->reject($callId, $user);

            return;
        }

        $otherUserId = null;
        $call = DB::transaction(function () use ($callId, $user, &$otherUserId) {
            $call = Call::query()->whereKey($callId)->lockForUpdate()->firstOrFail();

            if (! $call->isParticipant((int) $user->id)) {
                throw new \InvalidArgumentException('You are not a participant of this call.');
            }
            if ($call->status !== Call::STATUS_ACCEPTED) {
                throw new \InvalidArgumentException('Call is not active.');
            }

            $endedAt = now();
            $answeredAt = $call->answered_at ?: $call->started_at;
            $duration = $answeredAt ? max(0, $endedAt->diffInSeconds($answeredAt)) : null;

            $updated = Call::query()
                ->whereKey($call->id)
                ->where('status', Call::STATUS_ACCEPTED)
                ->update([
                    'status' => Call::STATUS_ENDED,
                    'ended_at' => $endedAt,
                    'duration' => $duration,
                    'updated_at' => $endedAt,
                ]);

            if ($updated !== 1) {
                throw new \InvalidArgumentException('Call is not active.');
            }

            $call->refresh();
            $otherUserId = (int) $call->caller_id === (int) $user->id
                ? (int) $call->callee_id
                : (int) $call->caller_id;

            return $call;
        });

        $this->publishToUserChannel((int) $otherUserId, 'call-ended', [
            'call_id' => $call->id,
        ]);
    }

    /**
     * Issue a fresh Agora RTC token for a participant of an active call.
     *
     * @return array{token: string, channel_name: string, app_id: string|null, uid: int, expires_in: int}
     */
    public function token(int $callId, User $user): array
    {
        $call = Call::findOrFail($callId);
        if (! $call->isParticipant((int) $user->id)) {
            throw new \InvalidArgumentException('You are not a participant of this call.');
        }

        $isCaller = (int) $call->caller_id === (int) $user->id;
        $canJoin = $call->status === Call::STATUS_ACCEPTED
            || ($call->isRinging() && $isCaller);

        if (! $canJoin) {
            throw new \InvalidArgumentException('Call is not joinable.');
        }

        $token = $this->agoraTokenService->generateRtcToken($call->channel_name, $user->id);
        if (! $token) {
            throw new \RuntimeException('Failed to generate Agora token.');
        }

        return [
            'token' => $token,
            'channel_name' => $call->channel_name,
            'app_id' => config('services.agora.app_id'),
            'uid' => (int) $user->id,
            'expires_in' => 3600,
            'call_type' => $call->type,
        ];
    }

    /**
     * Mark ringing calls past the timeout as missed, and end abandoned accepted calls.
     *
     * @return array{missed: int, ended: int}
     */
    public function cleanupStaleCalls(): array
    {
        return [
            'missed' => $this->markMissedRingingCalls(),
            'ended' => $this->expireStaleAcceptedCalls(),
        ];
    }

    /**
     * Mark ringing calls past the timeout as missed.
     *
     * @return int Number of calls marked missed
     */
    public function markMissedRingingCalls(): int
    {
        $cutoff = now()->subSeconds(Call::RING_TIMEOUT_SECONDS);
        $calls = Call::query()
            ->ringing()
            ->where('created_at', '<=', $cutoff)
            ->get();

        $count = 0;
        foreach ($calls as $call) {
            $call->update([
                'status' => Call::STATUS_MISSED,
                'ended_at' => now(),
            ]);

            $uuid = is_string($call->voip_uuid) ? $call->voip_uuid : null;
            $missedPayload = [
                'call_id' => $call->id,
                'uuid' => $uuid,
            ];

            $this->publishToUserChannel((int) $call->caller_id, 'call-missed', $missedPayload);
            $this->publishToUserChannel((int) $call->callee_id, 'call-missed', $missedPayload);
            // Legacy client compatibility.
            $this->publishToUserChannel((int) $call->caller_id, 'call-ended', [
                'call_id' => $call->id,
                'uuid' => $uuid,
            ]);
            $this->publishToUserChannel((int) $call->callee_id, 'call-ended', [
                'call_id' => $call->id,
                'uuid' => $uuid,
            ]);
            // Stop CallKit / CallKeep — same path as cancel().
            $this->signalCalleeStopRinging($call);
            $count++;
        }

        return $count;
    }

    /**
     * End accepted calls that never received a hangup (app crash / force quit).
     *
     * @return int Number of calls ended
     */
    public function expireStaleAcceptedCalls(): int
    {
        $cutoff = now()->subSeconds(Call::ACCEPTED_TIMEOUT_SECONDS);
        $calls = Call::query()
            ->accepted()
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNotNull('answered_at')
                        ->where('answered_at', '<=', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('answered_at')
                        ->where('started_at', '<=', $cutoff);
                });
            })
            ->get();

        $count = 0;
        foreach ($calls as $call) {
            $endedAt = now();
            $answeredAt = $call->answered_at ?: $call->started_at;
            $duration = $answeredAt ? max(0, $endedAt->diffInSeconds($answeredAt)) : null;

            $call->update([
                'status' => Call::STATUS_ENDED,
                'ended_at' => $endedAt,
                'duration' => $duration,
            ]);

            $this->publishToUserChannel((int) $call->caller_id, 'call-ended', [
                'call_id' => $call->id,
            ]);
            $this->publishToUserChannel((int) $call->callee_id, 'call-ended', [
                'call_id' => $call->id,
            ]);
            $count++;
        }

        return $count;
    }

    public function history(User $user, int $perPage = 20)
    {
        return Call::query()
            ->forUser((int) $user->id)
            ->with(['caller:id,name,image', 'callee:id,name,image'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
