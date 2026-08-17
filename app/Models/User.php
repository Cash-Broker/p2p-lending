<?php

namespace App\Models;

use App\Notifications\VerifyEmailNotification;
use App\Traits\Auditable;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use NotificationChannels\WebPush\PushSubscription;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    public const TYPE_INDIVIDUAL = 'individual';

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
            'dashboard_seen_at' => 'datetime',
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

    /**
     * Register (or refresh) THIS account's push subscription for a device.
     *
     * Deliberately NOT the package's `updatePushSubscription()`: that one
     * treats `endpoint` as globally unique and DELETES another account's row
     * for the same browser. Reni uses one phone for both the admin panel and
     * her investor profile (2026-08-17) and needs BOTH streams — uniqueness is
     * (endpoint + owner), so each account keeps its own row and a logout
     * revokes only that one.
     */
    public function registerPushSubscription(
        string $endpoint,
        string $publicKey,
        string $authToken,
        string $contentEncoding = 'aes128gcm',
    ): PushSubscription {
        return $this->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $endpoint],
            [
                'public_key' => $publicKey,
                'auth_token' => $authToken,
                'content_encoding' => $contentEncoding,
            ],
        );
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

    public function visitDays(): HasMany
    {
        return $this->hasMany(UserVisitDay::class);
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
