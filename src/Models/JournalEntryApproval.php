<?php

namespace Nml\FinCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryApproval extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'approver_id',
        'level',
        'action',
        'amount',
        'role',
        'comment',
    ];

    protected $casts = [
        'level'  => 'integer',
        'amount' => 'float',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'fincore_') . 'journal_entry_approvals';
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
