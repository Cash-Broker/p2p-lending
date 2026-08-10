<?php

namespace App\Models;

use Database\Factories\BorrowerAnonymizedProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BorrowerAnonymizedProfile extends Model
{
    /** @use HasFactory<BorrowerAnonymizedProfileFactory> */
    use HasFactory;

    /**
     * Canonical dropdown options (boss 2026-08-10 — «от падащо меню»).
     * Stored as the display strings themselves; older rows may carry
     * free-text values from before the dropdowns.
     */
    public const LOAN_PURPOSES = [
        'Потребителски кредит' => 'Потребителски кредит',
        'Мостов кредит' => 'Мостов кредит',
        'Ипотечен кредит' => 'Ипотечен кредит',
        'Бизнес кредит' => 'Бизнес кредит',
    ];

    public const AGE_GROUPS = [
        '20-30' => '20-30',
        '30-40' => '30-40',
        '40-50' => '40-50',
        '50-60' => '50-60',
        '60-70' => '60-70',
    ];

    public const COLLATERAL_TYPES = [
        'Няма' => 'Няма',
        'Съдлъжник' => 'Съдлъжник',
        'Ипотека' => 'Ипотека',
    ];

    protected $fillable = [
        'borrower_id',
        'risk_class',
        'region',
        'loan_purpose',
        'collateral_type',
        'age_group',
    ];

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }
}
