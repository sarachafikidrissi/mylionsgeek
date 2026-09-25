<?php

namespace App\Http\Controllers;

use Ably\AblyRest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PostNotification;
use App\Models\Post;
use App\Models\User;
use App\Services\AblyCapabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public const ATTACHMENT_DISK = 'attachments';

    /**
     * Get all conversations for the authenticated user
     */
    public function index()
    {
        try {
            $user = Auth::user();

            // IMPORTANT:
            // The inbox must include conversations where the user is the receiver,
            // even if they don't follow the sender back.
            // We keep "start/send messages only to users you follow" enforced elsewhere.
            $conversations = Conversation::where(function ($query) use ($user) {
                $query->where('user_one_id', $user->id)
                    ->orWhere('user_two_id', $user->id);
            })
            ->with(['userOne', 'userTwo'])
            ->orderBy('last_message_at', 'desc')
            ->get()
            ->map(function ($conversation) use ($user) {
                // Ensure relationships are loaded
                if (!$conversation->relationLoaded('userOne')) {
                    $conversation->load('userOne');
                }
                if (!$conversation->relationLoaded('userTwo')) {
                    $conversation->load('userTwo');
                }
                
                // Determine other user manually for safety
                $otherUser = $conversation->user_one_id == $user->id 
                    ? $conversation->userTwo 
                    : $conversation->userOne;
                
                if (!$otherUser) {
                    // Skip this conversation if we can't determine the other user
                    return null;
                }
                
                $unreadCount = $conversation->getUnreadCountForUser($user->id);
                
                // Get the actual last message (most recent by created_at)
                $lastMessage = Message::where('conversation_id', $conversation->id)
                    ->orderBy('created_at', 'desc')
                    ->first();

                return [
                    'id' => $conversation->id,
                    'other_user' => [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'image' => $otherUser->image,
                        'email' => $otherUser->email,
                        'last_login' => $otherUser->last_login ? Carbon::parse($otherUser->last_login)->toISOString() : null,
                        'last_online' => $otherUser->last_online ? Carbon::parse($otherUser->last_online)->toISOString() : null,
                    ],
                    'last_message' => $lastMessage ? [
                        'id' => $lastMessage->id,
                        'body' => $lastMessage->body,
                        'sender_id' => $lastMessage->sender_id,
                        'attachment_type' => $lastMessage->attachment_type,
                        'created_at' => $lastMessage->created_at->toISOString(),
                    ] : null,
                    'unread_count' => $unreadCount,
                    'last_message_at' => $conversation->last_message_at?->toISOString(),
                    'created_at' => $conversation->created_at->toISOString(),
                ];
            })
            ->filter(function ($conversation) {
                return $conversation !== null;
            })
            ->values(); // Re-index the array

            if (request()->header('X-Inertia')) {
                return redirect()->back()->with([
                    'conversations' => $conversations,
                ]);
            }

            return response()->json([
                'conversations' => $conversations,
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('ChatController@index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch conversations',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get or create a conversation between two users
     */
    public function getOrCreateConversation($userId)
    {
        $currentUser = Auth::user();

        if ($currentUser->id == $userId) {
            if (request()->header('X-Inertia')) {
                return redirect()->back()->withErrors(['error' => 'Cannot create conversation with yourself']);
            }
            return response()->json(['error' => 'Cannot create conversation with yourself'], 400);
        }

        // Check if conversation exists
        $conversation = Conversation::where(function ($query) use ($currentUser, $userId) {
            $query->where('user_one_id', $currentUser->id)
                ->where('user_two_id', $userId);
        })->orWhere(function ($query) use ($currentUser, $userId) {
            $query->where('user_one_id', $userId)
                ->where('user_two_id', $currentUser->id);
        })->first();

        // If no existing conversation, only allow creating one when the current user follows the target user.
        if (!$conversation) {
            $isFollowing = \App\Models\Follower::where('follower_id', $currentUser->id)
                ->where('followed_id', $userId)
                ->exists();

            if (!$isFollowing) {
                if (request()->header('X-Inertia')) {
                    return redirect()->back()->withErrors(['error' => 'You can only message users you follow']);
                }
                return response()->json(['error' => 'You can only message users you follow'], 403);
            }

            $conversation = Conversation::create([
                'user_one_id' => min($currentUser->id, $userId),
                'user_two_id' => max($currentUser->id, $userId),
            ]);
        }

        $conversation->load([
            'userOne',
            'userTwo',
            'messages' => function ($query) {
                $query->with([
                    'sender:id,name,image',
                    'replyTo.sender:id,name',
                    'reactions.user:id,name',
                ])->orderBy('created_at', 'asc');
            },
        ]);

        $otherUser = $conversation->getOtherUser($currentUser->id);

        $conversationData = [
            'id' => $conversation->id,
            'other_user' => [
                'id' => $otherUser->id,
                'name' => $otherUser->name,
                'image' => $otherUser->image,
                'email' => $otherUser->email,
                'last_login' => $otherUser->last_login ? Carbon::parse($otherUser->last_login)->toISOString() : null,
                'last_online' => $otherUser->last_online ? Carbon::parse($otherUser->last_online)->toISOString() : null,
            ],
            'messages' => $conversation->messages->map(fn ($message) => $this->serializeChatMessage($message, true)),
        ];

        if (request()->header('X-Inertia')) {
            return redirect()->back()->with([
                'conversation' => $conversationData,
            ]);
        }

        return response()->json([
            'conversation' => $conversationData,
        ]);
    }

    /**
     * Get messages for a specific conversation
     */
    public function getMessages($conversationId)
    {
        $user = Auth::user();

        $conversation = Conversation::where('id', $conversationId)
            ->where(function ($query) use ($user) {
                $query->where('user_one_id', $user->id)
                    ->orWhere('user_two_id', $user->id);
            })
            ->firstOrFail();

        $messagesQuery = $conversation->messages()
            ->with([
                'sender:id,name,image',
                'replyTo.sender:id,name',
                'reactions.user:id,name',
            ])
            ->orderBy('created_at', 'asc');

        // Optional windowing: ?limit=150 returns the latest N messages (chronological).
        // Optional cursor: ?before_id=123 returns older messages before that id.
        $limit = request()->integer('limit');
        $beforeId = request()->integer('before_id');
        if ($beforeId > 0) {
            $messagesQuery->where('id', '<', $beforeId);
        }
        if ($limit > 0) {
            $limit = min(200, $limit);
            $latestIds = (clone $messagesQuery)
                ->reorder()
                ->orderByDesc('created_at')
                ->limit($limit)
                ->pluck('id');
            $messagesQuery->whereIn('id', $latestIds);
        }

        $messages = $messagesQuery
            ->get()
            ->map(fn ($message) => $this->serializeChatMessage($message, true));

        // Mark messages as read only on the primary (latest-window) fetch, not when paging older.
        $updatedCount = 0;
        if ($beforeId <= 0) {
            $updatedCount = $conversation->messages()
                ->where('sender_id', '!=', $user->id)
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now(),
                ]);
        }

        // Broadcast seen status via Ably if messages were marked as read
        if ($updatedCount > 0) {
            try {
                $ablyKey = config('services.ably.key');
                if ($ablyKey) {
                    $ably = new AblyRest($ablyKey);
                    $channel = $ably->channels->get("chat:conversation:{$conversationId}");
                    
                    // Get the last message that was marked as read to include in the event
                    $lastReadMessage = $conversation->messages()
                        ->where('sender_id', '!=', $user->id)
                        ->where('is_read', true)
                        ->orderBy('read_at', 'desc')
                        ->first();
                    
                    $channel->publish('seen', [
                        'user_id' => $user->id,
                        'conversation_id' => $conversationId,
                        'read_at' => now()->toISOString(),
                        'last_message_id' => $lastReadMessage ? $lastReadMessage->id : null,
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to broadcast seen status via Ably: ' . $e->getMessage());
            }
        }

        // Always return JSON for fetch requests
        return response()->json([
            'messages' => $messages,
        ]);
    }

    /**
     * Get Ably token for real-time messaging.
     * Capabilities are derived from conversations and projects the user belongs to.
     */
    public function getAblyToken(AblyCapabilityService $capabilities)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $ablyKey = config('services.ably.key');
            if (!$ablyKey) {
                return response()->json(['error' => 'Ably not configured'], 500);
            }

            $tokenRequest = [
                'capability' => $capabilities->encode($capabilities->chatCapabilities($user)),
                'clientId' => (string) $user->id,
            ];

            $ably = new AblyRest($ablyKey);
            $tokenDetails = $ably->auth->requestToken($tokenRequest);

            return response()->json([
                'token' => $tokenDetails->token,
                'expires' => $tokenDetails->expires,
                'clientId' => (string) $user->id,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to generate token'], 500);
        }
    }

    /**
     * Send a message
     */
    public function sendMessage(Request $request, $conversationId)
    {
        $request->validate([
            // Some message bodies are JSON payloads (e.g. post shares) and can be longer than typical text.
            // DB column is TEXT, so keep a reasonable ceiling to prevent abuse but avoid false 422s.
            'body' => 'nullable|string|max:20000',
            'attachment' => [
                'nullable',
                'file',
                'max:10240',
                'mimetypes:'.implode(',', self::CHAT_ATTACHMENT_MIMETYPES),
            ],
            'attachment_type' => 'nullable|in:file,audio,image,video',
            'reply_to' => 'nullable|integer|exists:messages,id',
        ]);

        $user = Auth::user();

        $conversation = Conversation::where('id', $conversationId)
            ->where(function ($query) use ($user) {
                $query->where('user_one_id', $user->id)
                    ->orWhere('user_two_id', $user->id);
            })
            ->firstOrFail();

        // Get the other user
        $otherUserId = $conversation->user_one_id == $user->id
            ? $conversation->user_two_id
            : $conversation->user_one_id;

        // Follow is enforced when creating a conversation; existing threads can always reply.

        // Require either body or attachment
        if (empty($request->body) && !$request->hasFile('attachment')) {
            if (request()->header('X-Inertia')) {
                return redirect()->back()->withErrors(['error' => 'Message body or attachment is required']);
            }
            return response()->json(['error' => 'Message body or attachment is required'], 422);
        }

        $attachmentPath = null;
        $attachmentName = null;
        $attachmentType = null;

        // Handle file upload
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentName = $file->getClientOriginalName();
            $attachmentType = $this->chatAttachmentTypeFromMime($file->getMimeType());
            $attachmentPath = $file->store('chat/attachments', self::ATTACHMENT_DISK);
        }

        $replyToId = null;
        if ($request->filled('reply_to')) {
            $replyMessage = Message::where('id', (int) $request->reply_to)
                ->where('conversation_id', $conversation->id)
                ->first();
            if (! $replyMessage) {
                return response()->json(['error' => 'Invalid reply target'], 422);
            }
            $replyToId = $replyMessage->id;
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'reply_to' => $replyToId,
            'body' => (string) ($request->body ?? ''),
            'attachment_path' => $attachmentPath,
            'attachment_type' => $attachmentType,
            'attachment_name' => $attachmentName,
            'is_read' => false,
        ]);

        $isPostShareMessage = false;

        // If this message is a "shared post", create a real notification for the receiver
        try {
            $rawBody = (string) ($request->body ?? '');
            $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;

            if (is_array($decoded) && ($decoded['type'] ?? null) === 'post_share') {
                $isPostShareMessage = true;
                $sharedPostId = isset($decoded['post_id']) ? (int) $decoded['post_id'] : null;

                if ($sharedPostId) {
                    $sharedPost = Post::find($sharedPostId);

                    if ($sharedPost) {
                        $notification = PostNotification::createNotification(
                            $otherUserId,
                            $user->id,
                            $sharedPostId,
                            PostNotification::TYPE_SHARE
                        );

                        // Broadcast notification in real-time via Ably (same schema as PostController)
                        if ($notification) {
                            $ablyKey = config('services.ably.key');
                            if ($ablyKey) {
                                $ably = new AblyRest($ablyKey);
                                $channel = $ably->channels->get("notifications:{$otherUserId}");
                                $channel->publish('new_notification', [
                                    'id' => 'post-' . $notification->id,
                                    'type' => 'post_interaction',
                                    'sender_name' => $user->name,
                                    'sender_image' => $user->image,
                                    'message' => "{$user->name} shared a post with you",
                                    'link' => '/students/feed#post-' . $sharedPostId,
                                    'icon_type' => 'user',
                                    'created_at' => $notification->created_at->toISOString(),
                                    'post_id' => $sharedPostId,
                                    'interaction_type' => PostNotification::TYPE_SHARE,
                                ]);
                            }
                        }
                    } else {
                        Log::warning('Post share message received but post not found', [
                            'post_id' => $sharedPostId,
                            'conversation_id' => (int) $conversationId,
                            'sender_id' => (int) $user->id,
                            'receiver_id' => (int) $otherUserId,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to create/broadcast share notification', [
                'error' => $e->getMessage(),
                'conversation_id' => (int) $conversationId,
            ]);
        }

        // Send Expo push notification
        // For post shares we already notify via PostNotification (with its own Expo push + Ably notification),
        // so skip the generic chat push to avoid duplicate pushes.
        if ($message && !$isPostShareMessage) {
            try {
                \Illuminate\Support\Facades\Log::info('Attempting to send push notification for chat message', [
                    'message_id' => $message->id,
                    'conversation_id' => $conversation->id,
                    'sender_id' => $user->id,
                    'user_one_id' => $conversation->user_one_id,
                    'user_two_id' => $conversation->user_two_id,
                ]);
                
                // Use the correct field names: user_one_id and user_two_id
                $recipientId = $conversation->user_one_id == $user->id ? $conversation->user_two_id : $conversation->user_one_id;
                \Illuminate\Support\Facades\Log::info('Calculated recipient ID', ['recipient_id' => $recipientId]);
                
                /** @var \App\Models\User|null $recipient */
                $recipient = \App\Models\User::find($recipientId);
                
                if ($recipient && $user) {
                    \Illuminate\Support\Facades\Log::info('Recipient and sender found', [
                        'recipient_id' => $recipient->id,
                        'recipient_email' => $recipient->email,
                        'sender_id' => $user->id,
                    ]);
                    
                    // Reload recipient to get latest expo_push_token (Intelephense-friendly)
                    $recipient = $recipient->fresh();
                    if (!$recipient) {
                        \Illuminate\Support\Facades\Log::warning('Recipient disappeared after reload', [
                            'recipient_id' => $recipientId,
                            'conversation_id' => $conversation->id,
                        ]);
                        // Bail out gracefully (do not break message send)
                        return response()->json(['message' => 'Message sent'], 201);
                    }
                    
                    \Illuminate\Support\Facades\Log::info('Recipient refreshed', [
                        'recipient_id' => $recipient->id,
                        'has_expo_token' => !empty($recipient->expo_push_token),
                        'token_preview' => $recipient->expo_push_token ? substr($recipient->expo_push_token, 0, 30) . '...' : null,
                    ]);
                    
                    if ($recipient->expo_push_token) {
                        $pushService = app(\App\Services\ExpoPushNotificationService::class);
                        
                        $messageBody = $request->body ?? ($attachmentName ? "Sent an attachment" : "Sent a message");
                        $chatMessage = "{$user->name}: {$messageBody}";
                        
                        \Illuminate\Support\Facades\Log::info('Sending push notification for chat message', [
                            'recipient_id' => $recipientId,
                            'sender_id' => $user->id,
                            'conversation_id' => $conversation->id,
                            'message_id' => $message->id,
                            'chat_message' => $chatMessage,
                        ]);
                        
                        $success = $pushService->sendToUser($recipient, $user->name, $chatMessage, [
                            'type' => 'chat_message',
                            'conversation_id' => $conversation->id,
                            'message_id' => $message->id,
                            'sender_id' => $user->id,
                            'sender_name' => $user->name,
                        ]);
                        
                        if (!$success) {
                            \Illuminate\Support\Facades\Log::warning('Push notification send returned false for chat message', [
                                'recipient_id' => $recipientId,
                                'message_id' => $message->id,
                            ]);
                        } else {
                            \Illuminate\Support\Facades\Log::info('Push notification sent successfully for chat message', [
                                'recipient_id' => $recipientId,
                                'message_id' => $message->id,
                            ]);
                        }
                    } else {
                        \Illuminate\Support\Facades\Log::info('Recipient does not have Expo push token, skipping push notification', [
                            'recipient_id' => $recipientId,
                            'recipient_email' => $recipient->email,
                        ]);
                    }
                } else {
                    \Illuminate\Support\Facades\Log::warning('Recipient or sender not found for push notification', [
                        'recipient_found' => $recipient ? true : false,
                        'sender_found' => $user ? true : false,
                        'recipient_id' => $recipientId,
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send Expo push notification for chat message', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'conversation_id' => $conversation->id,
                    'message_id' => $message->id ?? null,
                ]);
                // Don't fail message creation if push fails
            }
        } else {
            \Illuminate\Support\Facades\Log::warning('Message not created, skipping push notification');
        }

        // When you reply, mark all previous messages from the other user as read
        // This means you've seen their messages
        $readCount = $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        // Update conversation's last_message_at
        $conversation->update([
            'last_message_at' => now(),
        ]);

        // Broadcast seen status if messages were marked as read
        if ($readCount > 0) {
            try {
                $ablyKey = config('services.ably.key');
                if ($ablyKey) {
                    $ably = new AblyRest($ablyKey);
                    $channel = $ably->channels->get("chat:conversation:{$conversationId}");
                    
                    // Get the last message that was marked as read
                    $lastReadMessage = $conversation->messages()
                        ->where('sender_id', '!=', $user->id)
                        ->where('is_read', true)
                        ->orderBy('read_at', 'desc')
                        ->first();
                    
                    $channel->publish('seen', [
                        'user_id' => $user->id,
                        'conversation_id' => $conversationId,
                        'read_at' => now()->toISOString(),
                        'last_message_id' => $lastReadMessage ? $lastReadMessage->id : null,
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to broadcast seen status when sending message: ' . $e->getMessage());
            }
        }

        $message->load([
            'sender:id,name,image',
            'replyTo.sender:id,name',
            'reactions.user:id,name',
        ]);

        $messageData = $this->serializeChatMessage($message, true);

        // Broadcast message via Ably for real-time updates
        try {
            $ablyKey = config('services.ably.key');
            if ($ablyKey) {
                $ably = new AblyRest($ablyKey);
                $channel = $ably->channels->get("chat:conversation:{$conversation->id}");
                $channel->publish('new-message', $messageData);
            }
        } catch (\Exception $e) {
            // Log error but don't fail the request
            \Illuminate\Support\Facades\Log::error('Failed to broadcast message via Ably: ' . $e->getMessage());
        }

        // Always return JSON for fetch requests
        return response()->json([
            'message' => $messageData,
        ], 201);
    }

    /**
     * Get IDs of users that the current user follows
     */
    public function getFollowingIds()
    {
        try {
            $user = Auth::user();
            $followingIds = \App\Models\Follower::where('follower_id', $user->id)
                ->pluck('followed_id')
                ->toArray();

            return response()->json([
                'following_ids' => $followingIds,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch following IDs',
                'following_ids' => []
            ], 500);
        }
    }

    /**
     * Get users that the authenticated user follows OR is followed by (for "Send to" picker).
     *
     * Mobile + web both use this list for the "Send to" modal.
     */
    public function getFollowingUsers()
    {
        try {
            $user = Auth::user();

            $followingIds = \App\Models\Follower::where('follower_id', $user->id)
                ->pluck('followed_id')
                ->toArray();

            $followerIds = \App\Models\Follower::where('followed_id', $user->id)
                ->pluck('follower_id')
                ->toArray();

            $contactIds = collect($followingIds)
                ->merge($followerIds)
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (empty($contactIds)) return response()->json(['users' => []]);

            $users = User::query()
                ->whereIn('id', $contactIds)
                ->select(['id', 'name', 'email', 'image', 'last_login', 'last_online'])
                ->orderBy('name')
                ->get()
                ->map(function ($u) {
                    return [
                        'id' => (int) $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'image' => $u->image,
                        'last_login' => $u->last_login ? Carbon::parse($u->last_login)->toISOString() : null,
                        'last_online' => $u->last_online ? Carbon::parse($u->last_online)->toISOString() : null,
                    ];
                })
                ->values();

            return response()->json(['users' => $users]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch following users',
                'users' => [],
            ], 500);
        }
    }

    /**
     * Get unread messages count
     */
    public function getUnreadCount()
    {
        $user = Auth::user();

        $conversations = Conversation::where('user_one_id', $user->id)
            ->orWhere('user_two_id', $user->id)
            ->get();

        $totalUnread = 0;
        foreach ($conversations as $conversation) {
            $totalUnread += $conversation->getUnreadCountForUser($user->id);
        }

        if (request()->header('X-Inertia')) {
            return redirect()->back()->with([
                'unread_count' => $totalUnread,
            ]);
        }

        return response()->json([
            'unread_count' => $totalUnread,
        ]);
    }

    /**
     * Mark conversation as read
     */
    public function markAsRead($conversationId)
    {
        $user = Auth::user();

        $conversation = Conversation::where('id', $conversationId)
            ->where(function ($query) use ($user) {
                $query->where('user_one_id', $user->id)
                    ->orWhere('user_two_id', $user->id);
            })
            ->firstOrFail();

        $updatedCount = $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        // Broadcast seen status via Ably
        if ($updatedCount > 0) {
            try {
                $ablyKey = config('services.ably.key');
                if ($ablyKey) {
                    $ably = new AblyRest($ablyKey);
                    $channel = $ably->channels->get("chat:conversation:{$conversationId}");
                    
                    // Get the last message that was marked as read to include in the event
                    $lastReadMessage = $conversation->messages()
                        ->where('sender_id', '!=', $user->id)
                        ->where('is_read', true)
                        ->orderBy('read_at', 'desc')
                        ->first();
                    
                    $channel->publish('seen', [
                        'user_id' => $user->id,
                        'conversation_id' => $conversationId,
                        'read_at' => now()->toISOString(),
                        'last_message_id' => $lastReadMessage ? $lastReadMessage->id : null,
                    ]);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to broadcast seen status via Ably: ' . $e->getMessage());
            }
        }

        // Always return JSON for fetch requests
        return response()->json(['success' => true]);
    }

    /**
     * Update a plain-text message body (no attachments / structured payloads).
     */
    public function updateMessage(Request $request, $messageId)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $message = Message::where('id', $messageId)
            ->where('sender_id', $user->id)
            ->firstOrFail();

        if (! $this->isPlainTextChatMessage($message)) {
            return response()->json([
                'message' => 'Only plain text messages can be edited.',
            ], 422);
        }

        $newBody = trim($validated['body']);
        if ($newBody === '') {
            return response()->json([
                'message' => 'Message body cannot be empty.',
            ], 422);
        }

        // Reject structured payloads masquerading as edits.
        if ($this->looksLikeStructuredChatPayload($newBody)) {
            return response()->json([
                'message' => 'Only plain text messages can be edited.',
            ], 422);
        }

        $message->body = $newBody;
        $message->save();
        $message->load([
            'sender:id,name,image',
            'replyTo.sender:id,name',
            'reactions.user:id,name',
        ]);

        $messageData = $this->serializeChatMessage($message, true, true);

        try {
            $ablyKey = config('services.ably.key');
            if ($ablyKey) {
                $ably = new AblyRest($ablyKey);
                $channel = $ably->channels->get("chat:conversation:{$message->conversation_id}");
                $channel->publish('message-updated', $messageData);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to broadcast message update via Ably: '.$e->getMessage());
        }

        return response()->json([
            'message' => $messageData,
        ]);
    }

    /**
     * Delete a message
     */
    public function deleteMessage($messageId)
    {
        $user = Auth::user();

        $message = Message::where('id', $messageId)
            ->where('sender_id', $user->id)
            ->firstOrFail();

        // Delete attachment file if exists
        if ($message->attachment_path) {
            $this->deleteStoredChatAttachment($message->attachment_path);
        }

        $conversationId = $message->conversation_id;
        $message->delete();

        // Broadcast deletion via Ably
        try {
            $ablyKey = config('services.ably.key');
            if ($ablyKey) {
                $ably = new AblyRest($ablyKey);
                $channel = $ably->channels->get("chat:conversation:{$conversationId}");
                $channel->publish('message-deleted', ['message_id' => $messageId]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to broadcast message deletion via Ably: ' . $e->getMessage());
        }

        // Always return JSON for fetch requests
        return response()->json(['success' => true]);
    }

    /**
     * Get posts for a user
     */
    public function getUserPosts($userId)
    {
        $userController = new \App\Http\Controllers\UsersController();
        $posts = $userController->getPosts($userId);

        // Always return JSON for fetch requests
        return response()->json(['posts' => $posts]);
    }

    /**
     * Delete a conversation
     */
    public function deleteConversation($conversationId)
    {
        $user = Auth::user();

        $conversation = Conversation::where('id', $conversationId)
            ->where(function ($query) use ($user) {
                $query->where('user_one_id', $user->id)
                    ->orWhere('user_two_id', $user->id);
            })
            ->firstOrFail();

        $conversation->delete();

        // Always return JSON for fetch requests
        return response()->json(['success' => true]);
    }

    /**
     * Toggle a reaction on a chat message (one reaction per user).
     */
    public function toggleReaction(Request $request, $messageId)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'reaction' => 'required|string|max:16',
        ]);

        $message = Message::query()->with('conversation')->findOrFail($messageId);
        $conversation = $message->conversation;

        if (
            ! $conversation
            || ($conversation->user_one_id !== $user->id && $conversation->user_two_id !== $user->id)
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $reaction = trim($validated['reaction']);
        $existing = \App\Models\MessageReaction::query()
            ->where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && $existing->reaction === $reaction) {
            $existing->delete();
        } else {
            \App\Models\MessageReaction::query()->updateOrCreate(
                [
                    'message_id' => $message->id,
                    'user_id' => $user->id,
                ],
                ['reaction' => $reaction]
            );
        }

        $message->load([
            'sender:id,name,image',
            'replyTo.sender:id,name',
            'reactions.user:id,name',
        ]);

        $messageData = $this->serializeChatMessage($message, true);

        try {
            $ablyKey = config('services.ably.key');
            if ($ablyKey) {
                $ably = new AblyRest($ablyKey);
                $channel = $ably->channels->get("chat:conversation:{$conversation->id}");
                $channel->publish('message-reaction-updated', $messageData);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to broadcast message reaction via Ably: '.$e->getMessage());
        }

        return response()->json([
            'message' => $messageData,
            'reactions' => $messageData['reactions'] ?? [],
        ]);
    }

    /**
     * Stream a chat attachment for conversation participants only.
     */
    public function downloadAttachment($messageId): BinaryFileResponse|StreamedResponse|\Illuminate\Http\Response
    {
        $user = Auth::user();
        $message = Message::query()->with('conversation')->findOrFail($messageId);
        $conversation = $message->conversation;

        if (! $conversation || ((int) $conversation->user_one_id !== (int) $user->id && (int) $conversation->user_two_id !== (int) $user->id)) {
            abort(403);
        }

        if (! $message->attachment_path) {
            abort(404);
        }

        $absolute = $this->resolveChatAttachmentAbsolutePath($message->attachment_path);
        if (! $absolute || ! is_readable($absolute)) {
            abort(404);
        }

        $name = $message->attachment_name ?: basename($message->attachment_path);

        return response()->file($absolute, [
            'Content-Disposition' => 'inline; filename="'.addslashes($name).'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeChatMessage(Message $message, bool $withSender = false, bool $forceEdited = false): array
    {
        $createdAt = $message->created_at?->toISOString();
        $updatedAt = $message->updated_at?->toISOString();
        $viewerId = Auth::id();

        $data = [
            'id' => $message->id,
            'body' => $message->body,
            'sender_id' => $message->sender_id,
            'reply_to' => $message->reply_to,
            'attachment_path' => $message->attachment_path,
            'attachment_url' => $message->attachment_path
                ? url('/api/mobile/chat/message/'.$message->id.'/attachment')
                : null,
            'attachment_url_web' => $message->attachment_path
                ? url('/chat/message/'.$message->id.'/attachment')
                : null,
            'attachment_type' => $message->attachment_type,
            'attachment_name' => $message->attachment_name,
            'attachment_size' => $this->chatAttachmentSize($message->attachment_path),
            'is_read' => $message->is_read,
            'read_at' => $message->read_at ? $message->read_at->toISOString() : null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'edited' => $forceEdited || (
                $message->created_at
                && $message->updated_at
                && $message->updated_at->gt($message->created_at)
            ),
            'reactions' => [],
            'my_reaction' => null,
            'reply_preview' => null,
        ];

        if ($message->relationLoaded('reactions')) {
            $grouped = $message->reactions->groupBy('reaction')->map(function ($reactions, $reaction) {
                return [
                    'reaction' => $reaction,
                    'count' => $reactions->count(),
                    'users' => $reactions->pluck('user.name')->filter()->values()->toArray(),
                ];
            })->values()->toArray();

            $data['reactions'] = $grouped;
            $mine = $message->reactions->firstWhere('user_id', $viewerId);
            $data['my_reaction'] = $mine?->reaction;
        }

        if ($message->relationLoaded('replyTo') && $message->replyTo) {
            $reply = $message->replyTo;
            $previewBody = trim((string) $reply->body);
            if ($previewBody === '' && $reply->attachment_type) {
                $previewBody = match ($reply->attachment_type) {
                    'image' => 'Photo',
                    'video' => 'Video',
                    'audio' => 'Voice message',
                    default => 'Attachment',
                };
            }
            $data['reply_preview'] = [
                'id' => $reply->id,
                'body' => $previewBody,
                'sender_id' => $reply->sender_id,
                'sender_name' => $reply->relationLoaded('sender') ? ($reply->sender->name ?? null) : null,
                'attachment_type' => $reply->attachment_type,
            ];
        }

        if ($withSender && $message->relationLoaded('sender') && $message->sender) {
            $data['sender'] = [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'image' => $message->sender->image,
            ];
        }

        return $data;
    }

    private function isPlainTextChatMessage(Message $message): bool
    {
        if ($message->attachment_path || $message->attachment_type) {
            return false;
        }

        $body = trim((string) $message->body);

        if ($body === '') {
            return false;
        }

        return ! $this->looksLikeStructuredChatPayload($body);
    }

    private function looksLikeStructuredChatPayload(string $body): bool
    {
        $trimmed = trim($body);
        if (! str_starts_with($trimmed, '{') || ! str_ends_with($trimmed, '}')) {
            return false;
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded) || ! isset($decoded['type'])) {
            return false;
        }

        return in_array($decoded['type'], ['post_share', 'story_reply'], true);
    }

    private function chatAttachmentSize(?string $relative): ?int
    {
        if (! $relative) {
            return null;
        }

        $absolute = $this->resolveChatAttachmentAbsolutePath($relative);

        return $absolute && is_file($absolute) ? filesize($absolute) : null;
    }

    private function resolveChatAttachmentAbsolutePath(string $relative): ?string
    {
        $private = Storage::disk(self::ATTACHMENT_DISK);
        if ($private->exists($relative)) {
            return $private->path($relative);
        }

        $public = Storage::disk('public');
        if ($public->exists($relative)) {
            return $public->path($relative);
        }

        return null;
    }

    private function deleteStoredChatAttachment(string $relative): void
    {
        Storage::disk(self::ATTACHMENT_DISK)->delete($relative);
        Storage::disk('public')->delete($relative);
    }

    /**
     * Product-supported chat attachment MIME types.
     * HTML, SVG, JS, PHP, and executables are intentionally excluded.
     */
    private const CHAT_ATTACHMENT_MIMETYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'audio/mpeg',
        'audio/mp3',
        'audio/mp4',
        'audio/x-m4a',
        'audio/m4a',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/webm',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private function chatAttachmentTypeFromMime(?string $mime): string
    {
        $mime = strtolower((string) $mime);

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        return 'file';
    }
}
