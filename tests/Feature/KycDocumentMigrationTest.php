<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the front/back split migration preserves the single KYC document
 * already uploaded in production. We use DatabaseMigrations (not RefreshDatabase)
 * because the test runs real migrate/rollback DDL, which would otherwise leak
 * out of a wrapping transaction and pollute the rest of the suite.
 */
class KycDocumentMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_single_document_is_preserved_as_front(): void
    {
        // Roll the schema back to its pre-deploy shape: a single
        // `kyc_document_path` column, exactly like production today.
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

        $this->assertTrue(Schema::hasColumn('users', 'kyc_document_path'));

        // Reproduce the real production record: one user whose only KYC upload
        // is the front of the ID card (status already approved).
        $id = DB::table('users')->insertGetId([
            'name' => 'Production User',
            'email' => 'prod-existing@example.com',
            'password' => bcrypt('secret'),
            'role' => 'investor',
            'kyc_status' => 'approved',
            'account_type' => 'individual',
            'kyc_document_path' => 'kyc-documents/existing-front.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Apply the deploy.
        Artisan::call('migrate', ['--force' => true]);

        $row = DB::table('users')->where('id', $id)->first();

        // The existing photo survives as the front image — no data loss.
        $this->assertSame('kyc-documents/existing-front.jpg', $row->kyc_document_front_path);
        // The back is simply empty; the admin can request it if needed.
        $this->assertNull($row->kyc_document_back_path);
        // Verification status is untouched.
        $this->assertSame('approved', $row->kyc_status);
        // The old column no longer exists.
        $this->assertFalse(Schema::hasColumn('users', 'kyc_document_path'));
        $this->assertTrue(Schema::hasColumn('users', 'kyc_document_front_path'));
    }
}
