<?php

namespace Nml\FinCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    protected $fillable = [
        'account_id',
        'sbu_code',
        'fiscal_year',
        'month',
        'amount',
    ];

    protected $casts = [
        'fiscal_year' => 'integer',
        'month' => 'integer',
        'amount' => 'float',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'fincore_') . 'budgets';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
