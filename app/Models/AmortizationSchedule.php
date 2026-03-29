<?php

namespace App\Models;

use Database\Factories\AmortizationScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmortizationSchedule extends Model
{
    /** @use HasFactory<AmortizationScheduleFactory> */
    use HasFactory;

    protected $fillable = [
        'loan_id',
        'due_date',
        'principal',
        'interest',
        'total',
        'status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'principal' => 'decimal:2',
            'interest' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
