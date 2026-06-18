<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Access grant: this user may view + invest in this PRIVATE loan (created when
 * they open its share link). See Loan::grantAccessTo() and LoanPolicy::view().
 */
class LoanGrant extends Model
{
    protected $fillable = [
        'loan_id',
        'user_id',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
