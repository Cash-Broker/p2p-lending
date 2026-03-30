<?php

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;

class LoanPolicy
{
    // Any authenticated user can browse published loans
    public function viewAny(User $user): bool
    {
        return true;
    }

    // Investors see only published/funding/active loans, admins see all
    public function view(User $user, Loan $loan): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return in_array($loan->status, Loan::INVESTOR_VISIBLE_STATUSES);
    }

    // Only admin can create/update loans
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Loan $loan): bool
    {
        return $user->isAdmin();
    }
}
