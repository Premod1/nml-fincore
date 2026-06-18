<?php

namespace Nml\FinCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'action',
        'user_id',
        'ip_address',
        'user_agent',
        'old_values',
        'new_values',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'fincore_') . 'audit_logs';
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
