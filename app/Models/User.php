<?php

namespace App\Models;

use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Traits\Auditable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, Auditable;

    public const TYPE_INDIVIDUAL   = 'individual';
    public const TYPE_LEGAL_ENTITY = 'legal_entity';

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'kyc_document_front_path',
        'kyc_document_back_path',
        'kyc_selfie_path',
        'account_type',
    ];

    // role and kyc_status are intentionally NOT fillable —
    // they must only change through admin actions, never from user input.
    // Default values are set in the migration (investor, pending).
    protected $attributes = [
        'role' => 'investor',
        'kyc_status' => 'pending',
        'account_type' => self::TYPE_INDIVIDUAL,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === 'admin';
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isInvestor(): bool
    {
        return $this->role === 'investor';
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function depositRequests(): HasMany
    {
        return $this->hasMany(DepositRequest::class);
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function consentRecords(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    /**
     * Document-consent types whose latest accepted version is stale (older than
     * the current version), and which therefore require re-consent.
     *
     * Fail-open on a *missing* record: registration always writes a record for
     * every document, so a user with no record at all for a type is not a
     * re-consent case (only test fixtures / pre-consent-system accounts) and is
     * not blocked. Fail-closed on a *stale* record — the real re-consent case.
     *
     * @return array<int, string>
     */
    public function outstandingConsents(): array
    {
        $current = ConsentRecord::currentDocumentVersions();

        $latestByType = $this->consentRecords()
            ->whereIn('type', array_keys($current))
            ->orderByDesc('accepted_at')
            ->get()
            ->groupBy('type');

        $pending = [];
        foreach ($current as $type => $version) {
            $group = $latestByType->get($type);
            if ($group === null) {
                continue; // No prior consent on record — not a re-consent case.
            }
            if ($group->first()->version !== $version) {
                $pending[] = $type;
            }
        }

        return $pending;
    }

    public function savedIbans(): HasMany
    {
        return $this->hasMany(SavedIban::class);
    }

    public function legalEntityProfile(): HasOne
    {
        return $this->hasOne(LegalEntityProfile::class);
    }

    public function isLegalEntity(): bool
    {
        return $this->account_type === self::TYPE_LEGAL_ENTITY;
    }

    public function isIndividual(): bool
    {
        return $this->account_type === self::TYPE_INDIVIDUAL;
    }
}
