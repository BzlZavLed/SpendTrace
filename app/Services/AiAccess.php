<?php

namespace App\Services;

use App\Models\User;

class AiAccess
{
    public function allowed(User $user): bool
    {
        return config('spendtrace.ai_preview_enabled', false)
            || $user->ai_access_expires_at?->isFuture() === true;
    }
}
