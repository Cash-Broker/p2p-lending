<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Frozen snapshot of the loan agreement («Договор за целеви паричен заем»)
 * concluded when an investor commits to an offer, PLUS the click-wrap
 * acceptance evidence (accepted_at / ip_address / user_agent).
 *
 * Created atomically inside InvestmentService::invest()'s transaction —
 * an offer-based investment without a contract row cannot exist (from the
 * feature's introduction onward; investments predating it have none).
 *
 * The contract PDF is rendered ON DEMAND from this snapshot
 * (InvestmentContractService::renderPdf) — no file is stored on disk.
 * `template_version` pins the Blade template used, so historical
 * contracts keep rendering with the exact wording they were accepted
 * under even if a v2 template ships later.
 *
 * `party_snapshot` contains PII decrypted at build time (names, ЕГН/ЕИК,
 * addresses) → encrypted at rest. `terms_snapshot` is commercial terms
 * only, kept queryable.
 *
 * GDPR note: rows survive account anonymization (AccountDeletionService)
 * on the Art. 17(3)(e) basis — evidence of a concluded contract for
 * establishment/defence of legal claims. A retention clock (e.g. 5y
 * after loan closure, ЗЗД/ЗМИП) is an OPEN product decision — see
 * CLAUDE.md «Open product decisions».
 *
 * App-level immutability guard: acceptance evidence is append-only.
 * performUpdate()/performDeleteOnModel() are overridden (not just
 * update()/delete()) so EVERY Eloquent instance write path throws —
 * save(), saveQuietly(), forceFill()->save(), touch(), delete().
 * NOT covered: query-builder bulk writes and raw SQL — closing those
 * requires a DB trigger like transactions/loan_events have, which is
 * deliberately deferred pending explicit sign-off (CLAUDE.md rule on
 * trigger changes).
 */
class InvestmentContract extends Model
{
    public const TEMPLATE_VERSION_V1 = 'v1';

    protected $fillable = [
        'investment_id',
        'user_id',
        'loan_id',
        'party_snapshot',
        'terms_snapshot',
        'template_version',
        'template_hash',
        'accepted_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'party_snapshot' => 'encrypted:array',
            'terms_snapshot' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    public function investment(): BelongsTo
    {
        return $this->belongsTo(Investment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Acceptance evidence must never be rewritten after the fact.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new LogicException('InvestmentContract records are immutable and cannot be updated.');
    }

    public function delete(): bool
    {
        throw new LogicException('InvestmentContract records are immutable and cannot be deleted.');
    }

    /**
     * The real chokepoints: every instance-level write funnels through
     * these regardless of events being suppressed (saveQuietly) or
     * attributes being force-filled.
     */
    protected function performUpdate($query): bool
    {
        throw new LogicException('InvestmentContract records are immutable and cannot be updated.');
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException('InvestmentContract records are immutable and cannot be deleted.');
    }
}
