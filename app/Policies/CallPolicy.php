<?php

namespace App\Policies;

use App\Models\Call;
use App\Models\User;

class CallPolicy
{
    public function view(User $user, Call $call): bool
    {
        return $call->isParticipant((int) $user->id);
    }

    public function accept(User $user, Call $call): bool
    {
        return (int) $call->callee_id === (int) $user->id && $call->isRinging();
    }

    public function reject(User $user, Call $call): bool
    {
        return (int) $call->callee_id === (int) $user->id && $call->isRinging();
    }

    public function cancel(User $user, Call $call): bool
    {
        return (int) $call->caller_id === (int) $user->id && $call->isRinging();
    }

    public function end(User $user, Call $call): bool
    {
        return $call->isParticipant((int) $user->id) && $call->isActive();
    }

    public function token(User $user, Call $call): bool
    {
        if (! $call->isParticipant((int) $user->id)) {
            return false;
        }

        // Accepted: both sides may refresh Agora tokens.
        if ($call->status === Call::STATUS_ACCEPTED) {
            return true;
        }

        // Ringing: only the caller (who already has an initiate token) may refresh.
        // Callee must accept before joining media.
        return $call->isRinging() && (int) $call->caller_id === (int) $user->id;
    }
}
