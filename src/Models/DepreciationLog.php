<?php

namespace Nml\FinCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepreciationLog extends Model
{
    protected $fillable = [
        'fixed_asset_id',
        'journal_entry_id',
        'depreciation_date',
        'amount',
    ];

    protected $casts = [
        'depreciation_date' => 'date',
        'amount' => 'float',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'fincore_') . 'depreciation_logs';
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
