<?php

namespace App\Policies;

use App\Models\DepositRequest;
use App\Models\User;

class DepositRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DepositRequest $depositRequest): bool
    {
        return $user->id === $depositRequest->user_id || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isInvestor();
    }
}
