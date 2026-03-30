<?php

namespace App\Policies;

use App\Models\Investment;
use App\Models\User;

class InvestmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Investment $investment): bool
    {
        return $user->id === $investment->user_id || $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isInvestor();
    }
}
