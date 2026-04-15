<?php

namespace App\Policies;

use App\Models\SavedIban;
use App\Models\User;

class SavedIbanPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SavedIban $iban): bool
    {
        return $user->id === $iban->user_id;
    }

    public function create(User $user): bool
    {
        return $user->isInvestor();
    }

    public function delete(User $user, SavedIban $iban): bool
    {
        return $user->id === $iban->user_id;
    }
}
