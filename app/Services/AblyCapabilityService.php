<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\GameSession;
use App\Models\Project;
use App\Models\User;

/**
 * Builds Ably token capabilities from server-side membership, never from client IDs.
 */
class AblyCapabilityService
{
    /**
     * Chat / feed / presence / project capabilities for the web+mobile chat token.
     *
     * @return array<string, list<string>>
     */
    public function chatCapabilities(User $user): array
    {
        $capabilities = [
            'feed:*' => ['subscribe'],
            'presence:global' => ['presence', 'subscribe'],
        ];

        foreach ($this->conversationIdsFor($user) as $conversationId) {
            $capabilities['chat:conversation:'.$conversationId] = ['subscribe', 'publish'];
        }

        foreach ($this->projectIdsFor($user) as $projectId) {
            $capabilities['project:'.$projectId] = ['subscribe'];
        }

        return $capabilities;
    }

    /**
     * Per-user call inbox only. Media runs on Agora; Ably is signaling.
     *
     * @return array<string, list<string>>
     */
    public function callCapabilities(User $user): array
    {
        return [
            'call:user:'.$user->id => ['subscribe'],
        ];
    }

    /**
     * Game rooms the user has joined (server-recorded participant_user_ids).
     *
     * @return array<string, list<string>>
     */
    public function gameCapabilities(User $user): array
    {
        $capabilities = [];

        GameSession::query()
            ->select(['room_id', 'game_state'])
            ->orderBy('id')
            ->each(function (GameSession $session) use ($user, &$capabilities) {
                if (! $this->userIsAuthorizedForGame($user, $session->game_state)) {
                    return;
                }
                $roomId = trim((string) $session->room_id);
                if ($roomId === '') {
                    return;
                }
                $capabilities['game:'.$roomId] = ['subscribe', 'publish'];
            });

        return $capabilities;
    }

    /**
     * Record that this authenticated user participated in the room (HTTP join/move).
     * Client-supplied participant_user_ids are ignored; only previous server ids plus the current user are kept.
     *
     * @param  list<int>  $previousParticipantIds
     */
    public function recordGameParticipant(GameSession $session, User $user, array $previousParticipantIds = []): void
    {
        $state = is_array($session->game_state) ? $session->game_state : [];
        $ids = array_map('intval', $previousParticipantIds);
        $ids[] = (int) $user->id;
        $state['participant_user_ids'] = array_values(array_unique($ids));
        $session->forceFill(['game_state' => $state])->save();
    }

    /**
     * Encode capabilities as a JSON object (never a bare array) for Ably token requests.
     *
     * @param  array<string, list<string>>  $capabilities
     */
    public function encode(array $capabilities): string
    {
        return json_encode((object) $capabilities, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<int>
     */
    public function conversationIdsFor(User $user): array
    {
        return Conversation::query()
            ->where(function ($query) use ($user) {
                $query->where('user_one_id', $user->id)
                    ->orWhere('user_two_id', $user->id);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    public function projectIdsFor(User $user): array
    {
        return Project::query()
            ->where(function ($query) use ($user) {
                $query->where('created_by', $user->id)
                    ->orWhereHas('users', function ($members) use ($user) {
                        $members->where('users.id', $user->id);
                    });
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Whether this user is already a member of the stored game state.
     * Used for Ably capabilities and HTTP game-state authorization.
     * Membership is only Auth::id() recorded in participant_user_ids.
     * Display names and client-supplied player objects are not identities.
     */
    public function userIsAuthorizedForGame(User $user, mixed $gameState): bool
    {
        if (! is_array($gameState)) {
            return false;
        }

        $participantIds = $gameState['participant_user_ids'] ?? [];
        if (! is_array($participantIds)) {
            return false;
        }

        $userId = (int) $user->id;

        foreach ($participantIds as $participantId) {
            if ((int) $participantId === $userId) {
                return true;
            }
        }

        return false;
    }
}
