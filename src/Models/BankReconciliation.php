<?php

namespace Nml\FinCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliation extends Model
{
    protected $fillable = [
        'account_id',
        'statement_date',
        'opening_balance',
        'closing_balance',
        'reconciled_at',
        'is_finalized',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'opening_balance' => 'float',
        'closing_balance' => 'float',
        'reconciled_at' => 'datetime',
        'is_finalized' => 'boolean',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'fincore_') . 'bank_reconciliations';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * Calculate the cleared balance of the reconciliation statement.
     * Cash/Bank accounts have normal debit balances.
     * Cleared Balance = Opening Balance + Cleared Debits - Cleared Credits
     */
    public function calculateClearedBalance(): float
    {
        $debits = (float) $this->lines()->where('type', 'debit')->sum('amount');
        $credits = (float) $this->lines()->where('type', 'credit')->sum('amount');

        return $this->opening_balance + $debits - $credits;
    }
}
