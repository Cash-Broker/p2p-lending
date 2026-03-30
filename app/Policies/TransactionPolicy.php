<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // Scoped to own transactions in controller query
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $user->id === $transaction->user_id || $user->isAdmin();
    }
}
