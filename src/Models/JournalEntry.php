<?php

namespace Nml\FinCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Enums\JvType;
use Nml\FinCore\Exceptions\AccountingException;

class JournalEntry extends Model
{
    protected $fillable = [
        'entry_number',
        'date',
        'reference',
        'type',
        'description',
        'status',
        'currency',
        'exchange_rate',
        'sbu_code',
        'journalable_type',
        'journalable_id',
        'created_by',
        'submitted_by',
        'approved_by',
        'submitted_at',
        'approved_at',
        'reviewer_note',
    ];

    protected $casts = [
        'status' => JvStatus::class,
        'type' => JvType::class,
        'date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'exchange_rate' => 'float',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'fincore_') . 'journal_entries';
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function journalable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Submit draft for approval.
     */
    public function submit(?int $userId = null): void
    {
        if ($this->status !== JvStatus::DRAFT) {
            throw new AccountingException("Only draft entries can be submitted.");
        }

        $this->update([
            'status' => JvStatus::SUBMITTED,
            'submitted_by' => $userId ?? auth()->id(),
            'submitted_at' => now(),
        ]);
    }

    /**
     * Post entry to General Ledger.
     */
    public function post(bool $bypassPeriodLock = false, ?int $userId = null): void
    {
        if (in_array($this->status, [JvStatus::POSTED, JvStatus::VOID])) {
            throw new AccountingException("Cannot post an entry that is already posted or voided.");
        }

        // 1. Enforce debit-credit balance in functional currency
        $tolerance = (float) config('accounting.rounding_tolerance', 0.005);
        $diff = abs($this->getDebitSum() - $this->getCreditSum());
        if ($diff > $tolerance) {
            throw new AccountingException("Debits ({$this->getDebitSum()}) and Credits ({$this->getCreditSum()}) must match within tolerance ({$tolerance}) in functional currency. Difference is {$diff}.");
        }

        // 2. Validate foreign currency balancing if it's a foreign currency transaction
        if ($this->currency !== config('accounting.currency', 'LKR')) {
            $fcDebit = (float) $this->lines()->where('type', 'debit')->sum('fc_amount');
            $fcCredit = (float) $this->lines()->where('type', 'credit')->sum('fc_amount');
            $fcDiff = abs($fcDebit - $fcCredit);
            if ($fcDiff > $tolerance) {
                throw new AccountingException("Foreign currency debits ({$fcDebit}) and credits ({$fcCredit}) must match within tolerance. Difference is {$fcDiff} {$this->currency}.");
            }
        }

        // 2. Enforce Period Lock
        if (!$bypassPeriodLock && config('accounting.enforce_period_lock', true)) {
            $isPeriodClosed = FiscalPeriod::where('start_date', '<=', $this->date)
                ->where('end_date', '>=', $this->date)
                ->where('is_closed', true)
                ->exists();

            $isYearClosed = FiscalYear::where('start_date', '<=', $this->date)
                ->where('end_date', '>=', $this->date)
                ->where('is_closed', true)
                ->exists();

            if ($isPeriodClosed || $isYearClosed) {
                throw new AccountingException("Cannot post to a closed accounting period/year. Date: " . $this->date->format('Y-m-d'));
            }
        }

        $this->update([
            'status' => JvStatus::POSTED,
            'approved_by' => $userId ?? auth()->id(),
            'approved_at' => now(),
        ]);
    }

    /**
     * Reject submitted entry and return to draft.
     */
    public function returnToDraft(string $note): void
    {
        if ($this->status !== JvStatus::SUBMITTED) {
            throw new AccountingException("Only submitted entries can be returned to draft.");
        }

        $this->update([
            'status' => JvStatus::DRAFT,
            'reviewer_note' => $note,
        ]);
    }

    /**
     * Void a posted or submitted entry.
     */
    public function void(): void
    {
        if ($this->status === JvStatus::VOID) {
            return;
        }

        if (config('accounting.enforce_period_lock', true)) {
            $isPeriodClosed = FiscalPeriod::where('start_date', '<=', $this->date)
                ->where('end_date', '>=', $this->date)
                ->where('is_closed', true)
                ->exists();

            $isYearClosed = FiscalYear::where('start_date', '<=', $this->date)
                ->where('end_date', '>=', $this->date)
                ->where('is_closed', true)
                ->exists();

            if ($isPeriodClosed || $isYearClosed) {
                throw new AccountingException("Cannot void an entry in a closed accounting period/year. Date: " . $this->date->format('Y-m-d'));
            }
        }

        $this->update([
            'status' => JvStatus::VOID,
        ]);
    }

    public function getDebitSum(): float
    {
        return (float) $this->lines()->where('type', 'debit')->sum('amount');
    }

    public function getCreditSum(): float
    {
        return (float) $this->lines()->where('type', 'credit')->sum('amount');
    }

    protected static function booted(): void
    {
        static::created(function (JournalEntry $entry) {
            \Nml\FinCore\Services\AuditLogService::log(
                action: 'created',
                journalEntryId: $entry->id,
                newValues: $entry->toArray(),
                userId: $entry->created_by ?? (auth()->check() ? auth()->id() : null)
            );
        });

        static::updated(function (JournalEntry $entry) {
            $dirty = $entry->getDirty();
            if (empty($dirty)) {
                return;
            }

            $old = array_intersect_key($entry->getOriginal(), $dirty);

            $action = 'updated';
            if (isset($dirty['status'])) {
                $action = $entry->status instanceof \BackedEnum ? $entry->status->value : (string) $entry->status;
            }

            \Nml\FinCore\Services\AuditLogService::log(
                action: $action,
                journalEntryId: $entry->id,
                oldValues: $old,
                newValues: $dirty,
                userId: auth()->check() ? auth()->id() : ($entry->approved_by ?? $entry->submitted_by)
            );
        });

        static::deleted(function (JournalEntry $entry) {
            \Nml\FinCore\Services\AuditLogService::log(
                action: 'deleted',
                journalEntryId: $entry->id,
                oldValues: $entry->toArray(),
                userId: auth()->check() ? auth()->id() : null
            );
        });
    }
}
