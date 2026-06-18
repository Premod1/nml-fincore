<?php

namespace Nml\FinCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAsset extends Model
{
    protected $fillable = [
        'name',
        'code',
        'purchase_date',
        'purchase_cost',
        'salvage_value',
        'useful_life_years',
        'depreciation_method',
        'depreciation_rate',
        'asset_account_id',
        'accumulated_depreciation_account_id',
        'depreciation_expense_account_id',
        'status',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'purchase_cost' => 'float',
        'salvage_value' => 'float',
        'useful_life_years' => 'integer',
        'depreciation_rate' => 'float',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'fincore_') . 'fixed_assets';
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    public function depreciationLogs(): HasMany
    {
        return $this->hasMany(DepreciationLog::class);
    }
}
