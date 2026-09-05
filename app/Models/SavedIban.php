<?php

namespace App\Models;

use App\Traits\Auditable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A payout destination.
 *
 * SEC-01 (owner 2026-09-03): a NEW IBAN is stored unconfirmed. The owner proves
 * mailbox possession through a signed link (SavedIbanConfirmationController);
 * withdrawals to it are possible only `withdrawal_new_iban_cooldown_hours`
 * (default 24) after the confirmation. Rows that predate the feature carry no
 * token and count as confirmed since creation — live investors keep working
 * after deploy without a single UPDATE.
 */
class SavedIban extends Model
{
    use Auditable, HasFactory;

    public const CONFIRMATION_TTL_MINUTES = 60;

    protected $fillable = [
        'user_id',
        'iban',
        'label',
        'confirmed_at',
        'confirmation_token_hash',
        'confirmation_sent_at',
        'confirmation_expires_at',
    ];

    protected $hidden = ['confirmation_token_hash'];

    protected function casts(): array
    {
        return [
            'iban' => 'encrypted',
            'confirmed_at' => 'immutable_datetime',
            'confirmation_sent_at' => 'immutable_datetime',
            'confirmation_expires_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function maskedIban(): string
    {
        return str_repeat('*', max(0, strlen($this->iban) - 4)).substr($this->iban, -4);
    }

    /** Rows created before the confirmation feature: no token was ever issued. */
    public function isLegacy(): bool
    {
        return $this->confirmed_at === null
            && $this->confirmation_token_hash === null
            && $this->confirmation_sent_at === null;
    }

    public function confirmedAt(): ?CarbonImmutable
    {
        if ($this->confirmed_at !== null) {
            return CarbonImmutable::instance($this->confirmed_at);
        }

        return $this->isLegacy() && $this->created_at !== null ? CarbonImmutable::instance($this->created_at) : null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmedAt() !== null;
    }

    public function withdrawableFrom(int $cooldownHours): ?CarbonImmutable
    {
        return $this->confirmedAt()?->addHours(max(0, $cooldownHours));
    }

    public function isWithdrawableAt(CarbonInterface $at, int $cooldownHours): bool
    {
        $from = $this->withdrawableFrom($cooldownHours);

        return $from !== null && ! $from->greaterThan($at);
    }

    public function confirmationExpired(): bool
    {
        return ! $this->isConfirmed()
            && $this->confirmation_expires_at !== null
            && $this->confirmation_expires_at->isPast();
    }

    /**
     * Generates the one-time token, stores ONLY its sha256 and returns the plain
     * value for the e-mail. Must be the only creation path for new rows — the
     * legacy rule («all NULL ⇒ confirmed since creation») depends on it.
     */
    public function issueConfirmationToken(): string
    {
        $plain = Str::random(64);

        $this->forceFill([
            'confirmation_token_hash' => hash('sha256', $plain),
            'confirmation_sent_at' => now(),
            'confirmation_expires_at' => now()->addMinutes(self::CONFIRMATION_TTL_MINUTES),
            'confirmed_at' => null,
        ]);

        return $plain;
    }

    public function matchesConfirmationToken(string $plain): bool
    {
        return $this->confirmation_token_hash !== null
            && hash_equals($this->confirmation_token_hash, hash('sha256', $plain));
    }
}
