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
