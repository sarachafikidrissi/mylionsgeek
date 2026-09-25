<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoPushNotificationService
{
    /**
     * Expo Push Notification API endpoint
     */
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    /**
     * Send a push notification to a user
     * 
     * @param User $user The user to send the notification to
     * @param string $title Notification title
     * @param string $body Notification body/message
     * @param array $data Additional data payload (optional)
     * @return bool Success status
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): bool
    {
        // Refresh user to ensure we have latest expo_push_token
        $user->refresh();
        
        // Check if user has an Expo push token
        if (!$user->expo_push_token) {
            Log::info('User does not have Expo push token', [
                'user_id' => $user->id,
                'user_email' => $user->email,
            ]);
            return false;
        }

        Log::info('Sending push notification to user', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'token_preview' => substr($user->expo_push_token, 0, 30) . '...',
            'title' => $title,
            'body' => $body,
        ]);

        return $this->send($user->expo_push_token, $title, $body, $data);
    }

    /**
     * Send push notification to multiple tokens
     * 
     * @param array|string $tokens Single token or array of tokens
     * @param string $title Notification title
     * @param string $body Notification body/message
     * @param array $data Additional data payload (optional)
     * @return bool Success status
     */
    public function send($tokens, string $title, string $body, array $data = []): bool
    {
        // Normalize tokens to array
        $tokenArray = is_array($tokens) ? $tokens : [$tokens];
        
        // Filter out empty tokens
        $tokenArray = array_filter($tokenArray, function($token) {
            return !empty($token) && is_string($token);
        });

        if (empty($tokenArray)) {
            Log::warning('No valid Expo push tokens provided');
            return false;
        }

        // Validate token format (should be ExponentPushToken[...])
        foreach ($tokenArray as $index => $token) {
            if (!preg_match('/^ExponentPushToken\[.+\]$/', $token)) {
                Log::warning('Invalid Expo push token format', [
                    'token_preview' => substr($token, 0, 50) . '...',
                    'expected_format' => 'ExponentPushToken[...]',
                    'token_length' => strlen($token),
                ]);
                // Don't fail, just log - Expo API will handle invalid tokens
            }
        }

        // For incoming-call pushes, route through the high-priority Android
        // notification channel registered in the mobile app so the device
        // rings persistently like a phone call (loud, repeating vibration,
        // bypass Do Not Disturb).
        $isCallWake = isset($data['type']) && in_array($data['type'], ['incoming_call', 'call_cancelled'], true);
        $channelId = ($data['type'] ?? null) === 'incoming_call' ? 'incoming-calls' : 'default';
        $iosInterruptionLevel = $isCallWake ? 'time-sensitive' : 'active';

        // Prepare messages for Expo API
        $messages = [];
        foreach ($tokenArray as $token) {
            $payload = [
                'to' => $token,
                'data' => $data,
                'priority' => 'high',
                'channelId' => $channelId,
            ];

            if ($isCallWake) {
                // Data-only wake: native CallKit/CallKeep + in-app ringtone.
                // Do not attach title/body/sound or the OS shows a chat-style banner.
                $payload['contentAvailable'] = true;
                $payload['_contentAvailable'] = true;
                $payload['ttl'] = $data['type'] === 'call_cancelled' ? 30 : 45;
            } else {
                $payload['sound'] = 'default';
                $payload['title'] = $title;
                $payload['body'] = $body;
                $payload['_displayInForeground'] = true;
                $payload['interruptionLevel'] = $iosInterruptionLevel;
            }

            $messages[] = $payload;
        }
        
        Log::info('Prepared messages for Expo API', [
            'message_count' => count($messages),
            'first_token_preview' => substr($tokenArray[0] ?? '', 0, 50) . '...',
        ]);

        try {
            Log::info('Sending Expo push notification', [
                'token_count' => count($messages),
                'titles' => array_column($messages, 'title'),
            ]);

            // Expo API expects the request body to be a JSON array directly: [{"to": "...", ...}]
            $request = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Content-Type' => 'application/json',
                ]);

            $certPath = storage_path('certs/cacert.pem');
            if (is_file($certPath)) {
                $request = $request->withOptions(['verify' => $certPath]);
            }

            $response = $request->post(self::EXPO_PUSH_URL, $messages);

            $statusCode = $response->status();
            $responseBody = $response->body();
            $responseData = $response->json();
            
            Log::info('Expo API response received', [
                'status' => $statusCode,
                'successful' => $statusCode >= 200 && $statusCode < 300,
                'body_preview' => substr($responseBody, 0, 500),
            ]);

            if ($statusCode >= 200 && $statusCode < 300) {
                if ($responseData === null) {
                    Log::error('Failed to parse Expo API response as JSON', [
                        'body' => $responseBody,
                    ]);
                    return false;
                }
                
                Log::info('Expo API response data', [
                    'response' => $responseData,
                ]);
                
                // Check for errors in response
                if (isset($responseData['data'])) {
                    $data = $responseData['data'];
                    
                    // Expo API can return data as either an array (multiple messages) or object (single message)
                    // Normalize to array for processing
                    if (!is_array($data)) {
                        $data = [$data];
                    }
                    
                    $hasErrors = false;
                    $errorMessages = [];
                    
                    foreach ($data as $result) {
                        // Handle both array and object formats
                        $status = is_array($result) ? ($result['status'] ?? null) : ($result->status ?? null);
                        
                        if ($status === 'error') {
                            $hasErrors = true;
                            $errorMsg = is_array($result) ? ($result['message'] ?? 'Unknown error') : ($result->message ?? 'Unknown error');
                            $errorCode = null;
                            
                            // Handle error details - can be object or array
                            $details = is_array($result) ? ($result['details'] ?? null) : ($result->details ?? null);
                            if ($details) {
                                if (is_object($details)) {
                                    $errorCode = $details->error ?? null;
                                } elseif (is_array($details)) {
                                    $errorCode = $details['error'] ?? null;
                                }
                            }
                            
                            $token = is_array($result) ? ($result['to'] ?? '') : ($result->to ?? '');
                            
                            $errorMessages[] = [
                                'message' => $errorMsg,
                                'error_code' => $errorCode,
                                'token_preview' => substr($token, 0, 30) . '...',
                            ];
                            
                            Log::warning('Expo push notification error', [
                                'error' => $errorMsg,
                                'error_code' => $errorCode,
                                'token_preview' => substr($token, 0, 30) . '...',
                                'full_result' => $result,
                            ]);
                        } else {
                            // Success case
                            $id = is_array($result) ? ($result['id'] ?? null) : ($result->id ?? null);
                            $statusValue = $status ?? 'unknown';
                            
                            Log::info('Expo push notification success', [
                                'status' => $statusValue,
                                'id' => $id,
                            ]);
                        }
                    }
                    
                    if (!$hasErrors) {
                        Log::info('Expo push notifications sent successfully', [
                            'count' => count($messages),
                        ]);
                        return true;
                    } else {
                        Log::warning('Some Expo push notifications had errors', [
                            'count' => count($messages),
                            'errors' => $errorMessages,
                        ]);
                        return false;
                    }
                }
                
                // If no data field, check if response indicates success
                if (isset($responseData['errors']) && !empty($responseData['errors'])) {
                    Log::error('Expo API returned errors', [
                        'errors' => $responseData['errors'],
                        'full_response' => $responseData,
                    ]);
                    return false;
                }
                
                Log::info('Expo push notification response (no data field)', [
                    'response' => $responseData,
                ]);
                return true;
            } else {
                Log::error('Failed to send Expo push notification', [
                    'status' => $statusCode,
                    'body' => $responseBody,
                    'json' => $responseData,
                ]);
                
                // Return false but log detailed error
                return false;
            }
        } catch (\Exception $e) {
            Log::error('Exception while sending Expo push notification', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Send push notification to multiple users
     * 
     * @param array $users Array of User models
     * @param string $title Notification title
     * @param string $body Notification body/message
     * @param array $data Additional data payload (optional)
     * @return int Number of successful sends
     */
    public function sendToUsers(array $users, string $title, string $body, array $data = []): int
    {
        $successCount = 0;
        
        foreach ($users as $user) {
            if ($this->sendToUser($user, $title, $body, $data)) {
                $successCount++;
            }
        }
        
        return $successCount;
    }

    /**
     * High-priority data-only message (no banner / no notification sound).
     * Wakes CallKeep on Android and stops ringing after hangup.
     */
    public function sendDataOnly(User $user, array $data): bool
    {
        $user->refresh();
        if (! $user->expo_push_token) {
            return false;
        }

        return $this->send($user->expo_push_token, '', '', $data);
    }

    /**
     * Broadcast an announcement to users who registered an Expo push token (100 tokens per Expo request).
     */
    public function sendAnnouncementPush(string $title, string $body, int $announcementId): int
    {
        $pushBody = \Illuminate\Support\Str::limit($body, 178);
        $data = [
            'type' => 'announcement',
            'announcement_id' => $announcementId,
        ];

        $delivered = 0;

        User::query()
            ->whereNotNull('expo_push_token')
            ->where('expo_push_token', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($title, $pushBody, $data, &$delivered) {
                $tokens = $users->pluck('expo_push_token')->filter()->values()->all();

                if (empty($tokens)) {
                    return;
                }

                if ($this->send($tokens, $title, $pushBody, $data)) {
                    $delivered += count($tokens);
                }
            });

        Log::info('Announcement push completed', [
            'announcement_id' => $announcementId,
            'delivered' => $delivered,
        ]);

        return $delivered;
    }

    /**
     * Broadcast a new public event to users with an Expo push token (100 tokens per Expo request).
     */
    public function sendEventPush(string $title, string $body, int $eventId): int
    {
        $pushBody = \Illuminate\Support\Str::limit($body, 178);
        $data = [
            'type' => 'event',
            'event_id' => $eventId,
        ];

        $delivered = 0;

        User::query()
            ->whereNotNull('expo_push_token')
            ->where('expo_push_token', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($title, $pushBody, $data, &$delivered) {
                $tokens = $users->pluck('expo_push_token')->filter()->values()->all();

                if (empty($tokens)) {
                    return;
                }

                if ($this->send($tokens, $title, $pushBody, $data)) {
                    $delivered += count($tokens);
                }
            });

        Log::info('Event push completed', [
            'event_id' => $eventId,
            'delivered' => $delivered,
        ]);

        return $delivered;
    }
}
