<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WithdrawalRequest;

class WithdrawalRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WithdrawalRequest $withdrawalRequest): bool
    {
        return $user->id === $withdrawalRequest->user_id || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isInvestor();
    }
}
