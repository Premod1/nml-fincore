<?php

namespace Nml\FinCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'type',
        'amount',
        'description',
        'cleared_at',
        'bank_reconciliation_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'cleared_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'fincore_') . 'journal_entry_lines';
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function bankReconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class);
    }
}
