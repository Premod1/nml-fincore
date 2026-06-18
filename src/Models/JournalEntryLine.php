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
        'fc_amount',
        'tax_id',
        'description',
        'cleared_at',
        'bank_reconciliation_id',
        'due_date',
        'partnerable_type',
        'partnerable_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'fc_amount' => 'float',
        'tax_id' => 'integer',
        'cleared_at' => 'datetime',
        'due_date' => 'date',
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

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function partnerable(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }
}
