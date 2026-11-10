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
        'rejected_by',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'reviewer_note',
        'rejection_reason',
        'approval_level',
    ];

    protected $casts = [
        'status'         => JvStatus::class,
        'type'           => JvType::class,
        'date'           => 'date',
        'submitted_at'   => 'datetime',
        'approved_at'    => 'datetime',
        'rejected_at'    => 'datetime',
        'exchange_rate'  => 'float',
        'approval_level' => 'integer',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'fincore_') . 'journal_entries';
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(JournalEntryApproval::class);
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
        if (!in_array($this->status, [JvStatus::DRAFT, JvStatus::REJECTED])) {
            throw new AccountingException("Only draft or rejected entries can be submitted.");
        }

        $this->update([
            'status'           => JvStatus::PENDING_APPROVAL,
            'submitted_by'     => $userId ?? auth()->id(),
            'submitted_at'     => now(),
            'rejection_reason' => null,
        ]);
    }

    /**
     * Submit draft for approval via ApprovalWorkflowEngine.
     */
    public function submitForApproval(?int $userId = null): void
    {
        $fresh = (new \Nml\FinCore\Services\ApprovalWorkflowEngine())->submitForApproval($this, $userId);
        $this->fill($fresh->getAttributes());
        $this->syncOriginal();
    }

    /**
     * Approve entry via ApprovalWorkflowEngine.
     */
    public function approve(int|string $approverId, ?string $comment = null, ?string $role = null): void
    {
        $fresh = (new \Nml\FinCore\Services\ApprovalWorkflowEngine())->approve($this, $approverId, $comment, $role);
        $this->fill($fresh->getAttributes());
        $this->syncOriginal();
    }

    /**
     * Reject entry with a mandatory reason via ApprovalWorkflowEngine.
     */
    public function reject(int|string $approverId, string $reason, ?string $role = null): void
    {
        $fresh = (new \Nml\FinCore\Services\ApprovalWorkflowEngine())->reject($this, $approverId, $reason, $role);
        $this->fill($fresh->getAttributes());
        $this->syncOriginal();
    }

    /**
     * Check if a user is authorized to approve this entry.
     */
    public function canApprove(int|string $userId, ?string $role = null): bool
    {
        return (new \Nml\FinCore\Services\ApprovalWorkflowEngine())->canApprove($this, $userId, $role);
    }

    /**
     * Post entry to General Ledger.
     */
    public function post(bool $bypassPeriodLock = false, ?int $userId = null, bool $bypassApproval = false): void
    {
        if (in_array($this->status, [JvStatus::POSTED, JvStatus::VOID])) {
            throw new AccountingException("Cannot post an entry that is already posted or voided.");
        }

        // Validate approval workflow status if enabled
        if (!$bypassApproval && config('accounting.approval_workflow.enabled', false)) {
            if ($this->status !== JvStatus::APPROVED) {
                throw new AccountingException("Cannot post entry in status '{$this->status->value}'. Approval workflow is enabled; entry must be approved first.");
            }
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

        // 3. Enforce Period Lock
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
            'approved_by' => $userId ?? auth()->id() ?? $this->approved_by,
            'approved_at' => $this->approved_at ?? now(),
        ]);
    }

    /**
     * Reject or return entry to draft.
     */
    public function returnToDraft(string $note): void
    {
        if (!in_array($this->status, [JvStatus::SUBMITTED, JvStatus::PENDING_APPROVAL, JvStatus::REJECTED])) {
            throw new AccountingException("Only submitted, pending_approval, or rejected entries can be returned to draft.");
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

        // Clean up associated DepreciationLog if this was a depreciation entry (Issue #6)
        \Nml\FinCore\Models\DepreciationLog::where('journal_entry_id', $this->id)->delete();
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

        static::updating(function (JournalEntry $entry) {
            $originalStatus = $entry->getOriginal('status');
            if ($originalStatus instanceof JvStatus) {
                $originalStatus = $originalStatus->value;
            }

            if ($originalStatus === JvStatus::POSTED->value) {
                // When already posted, only allow transitioning to VOID or updating review metadata
                $dirty = array_keys($entry->getDirty());
                $allowedFields = ['status', 'approved_by', 'approved_at', 'reviewer_note', 'updated_at'];
                $disallowedDirty = array_diff($dirty, $allowedFields);

                if (!empty($disallowedDirty)) {
                    throw new AccountingException(
                        "Cannot modify posted journal entry fields (" . implode(', ', $disallowedDirty) . "). Posted entries are immutable; reverse or void instead."
                    );
                }

                $newStatus = $entry->status instanceof JvStatus ? $entry->status->value : (string) $entry->status;
                if ($newStatus !== JvStatus::POSTED->value && $newStatus !== JvStatus::VOID->value) {
                    throw new AccountingException("Cannot revert a posted journal entry to {$newStatus}. Only voiding is permitted.");
                }
            } elseif ($originalStatus === JvStatus::VOID->value) {
                throw new AccountingException("Cannot modify a voided journal entry.");
            }
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

        static::deleting(function (JournalEntry $entry) {
            $status = $entry->getOriginal('status');
            if ($status instanceof JvStatus) {
                $status = $status->value;
            }

            if ($status === JvStatus::POSTED->value) {
                throw new AccountingException("Cannot delete a posted journal entry. Posted entries are immutable and must remain in the general ledger for compliance. Void the entry instead.");
            }

            \Nml\FinCore\Services\AuditLogService::log(
                action: 'deleted',
                journalEntryId: $entry->id,
                oldValues: $entry->toArray(),
                userId: auth()->check() ? auth()->id() : null
            );
        });
    }
}
