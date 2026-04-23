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

    /**
     * Investor may read the loan's lifecycle events ONLY if they have at
     * least one investment in this loan (any amount, any time, even fully
     * repaid). Admin sees everything via Filament; this guard is for the
     * investor-side /loans/{loan}/events API endpoint.
     *
     * Rationale: events expose timeline data (loan went late, recovered)
     * which is sensitive even though the LoanEventResource sanitises
     * metadata. A non-investor browsing the marketplace shouldn't see
     * the lifecycle history of every loan.
     */
    public function viewEvents(User $user, Loan $loan): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        return $loan->investments()->where('user_id', $user->id)->exists();
    }
}
