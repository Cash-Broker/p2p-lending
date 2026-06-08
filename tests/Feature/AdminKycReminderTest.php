<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminKycReminderTest extends TestCase
{
    use RefreshDatabase;

    private function userWithKyc(string $status): User
    {
        // kyc_status is intentionally guarded, so set it directly.
        $user = User::factory()->create();
        $user->forceFill(['kyc_status' => $status])->save();

        return $user;
    }

    public function test_badge_counts_only_submitted_users(): void
    {
        $this->userWithKyc('submitted');
        $this->userWithKyc('submitted');
        $this->userWithKyc('approved');
        $this->userWithKyc('rejected');
        $this->userWithKyc('pending');

        $this->assertSame('2', UserResource::getNavigationBadge());
        $this->assertSame('warning', UserResource::getNavigationBadgeColor());
    }

    public function test_badge_is_hidden_when_nothing_pending(): void
    {
        $this->userWithKyc('pending');
        $this->userWithKyc('approved');

        // null hides the badge entirely — no "0" clutter in the sidebar.
        $this->assertNull(UserResource::getNavigationBadge());
    }
}
