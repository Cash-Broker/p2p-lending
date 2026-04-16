<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Borrower carries the most sensitive PII (encrypted personal_id, full_name,
 * address, phone). Without auditing, a rogue admin or compromised account can
 * silently alter borrower records — breaking non-repudiation and the GDPR
 * Article 30 record-of-processing requirement. The Auditable trait must log
 * every CRUD event AND redact PII fields from the stored values.
 */
class BorrowerAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_creating_borrower_writes_audit_log(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $borrower = Borrower::create([
            'full_name' => 'Иван Иванов',
            'personal_id' => '8001011234',
            'address' => 'София, ул. Витоша 1',
            'phone' => '+359888111222',
            'income' => '2500.00',
        ]);

        $log = AuditLog::where('model_type', Borrower::class)
            ->where('model_id', $borrower->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log, 'No audit log entry for borrower create');
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_audit_log_redacts_borrower_pii_fields(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $borrower = Borrower::create([
            'full_name' => 'Иван Иванов',
            'personal_id' => '8001011234',
            'address' => 'София, ул. Витоша 1',
            'phone' => '+359888111222',
            'income' => '2500.00',
        ]);

        $log = AuditLog::where('model_type', Borrower::class)
            ->where('model_id', $borrower->id)
            ->where('action', 'created')
            ->first();

        // Sensitive fields must be redacted in stored audit values.
        $this->assertSame('[REDACTED]', $log->new_values['full_name']);
        $this->assertSame('[REDACTED]', $log->new_values['personal_id']);
        $this->assertSame('[REDACTED]', $log->new_values['address']);
        $this->assertSame('[REDACTED]', $log->new_values['phone']);

        // Non-sensitive financial fields are preserved.
        $this->assertSame('2500.00', $log->new_values['income']);
    }

    public function test_updating_borrower_writes_audit_log_with_redacted_pii(): void
    {
        $admin = $this->admin();
        $borrower = Borrower::create([
            'full_name' => 'Иван Иванов',
            'personal_id' => '8001011234',
            'address' => 'София, ул. Витоша 1',
            'phone' => '+359888111222',
            'income' => '2500.00',
        ]);

        $this->actingAs($admin);
        $borrower->update([
            'address' => 'Пловдив, ул. Главна 5',
            'income' => '3000.00',
        ]);

        $log = AuditLog::where('model_type', Borrower::class)
            ->where('model_id', $borrower->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('[REDACTED]', $log->new_values['address'] ?? null);
        $this->assertSame('[REDACTED]', $log->old_values['address'] ?? null);
        $this->assertSame('3000.00', $log->new_values['income'] ?? null);
    }

    public function test_deleting_borrower_writes_audit_log(): void
    {
        $admin = $this->admin();
        $borrower = Borrower::create([
            'full_name' => 'Иван Иванов',
            'personal_id' => '8001011234',
            'address' => 'София',
            'phone' => '+359888111222',
            'income' => '2500.00',
        ]);
        $borrowerId = $borrower->id;

        $this->actingAs($admin);
        $borrower->delete();

        $log = AuditLog::where('model_type', Borrower::class)
            ->where('model_id', $borrowerId)
            ->where('action', 'deleted')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('[REDACTED]', $log->old_values['personal_id']);
    }
}
