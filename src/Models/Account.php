<?php

namespace Nml\FinCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Enums\JvStatus;

class Account extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'subtype',
        'parent_id',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'type' => AccountType::class,
        'is_active' => 'boolean',
    ];

    public function getTable()
    {
        return config('accounting.table_prefix', 'fincore_') . 'accounts';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * Determine if the account type has a normal debit balance.
     * Assets and Expenses are normally debits.
     * Liabilities, Equities, and Revenues are normally credits.
     */
    public function hasNormalDebitBalance(): bool
    {
        return in_array($this->type, [AccountType::ASSET, AccountType::EXPENSE]);
    }

    /**
     * Calculate the current posted balance of the account (and optionally its sub-accounts).
     */
    public function getCurrentBalance(
        bool $includeChildren = true,
        ?string $sbuCode = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): float {
        $accountIds = [$this->id];

        if ($includeChildren) {
            $accountIds = array_merge($accountIds, $this->getAllChildIds());
        }

        $query = JournalEntryLine::query()
            ->whereIn('account_id', $accountIds)
            ->whereHas('journalEntry', function ($q) use ($sbuCode, $startDate, $endDate) {
                $q->where('status', JvStatus::POSTED);
                if ($sbuCode) {
                    $q->where('sbu_code', $sbuCode);
                }
                if ($startDate) {
                    $q->where('date', '>=', $startDate);
                }
                if ($endDate) {
                    $q->where('date', '<=', $endDate);
                }
            });

        $debits = (float) (clone $query)->where('type', 'debit')->sum('amount');
        $credits = (float) (clone $query)->where('type', 'credit')->sum('amount');

        if ($this->hasNormalDebitBalance()) {
            return $debits - $credits;
        }

        return $credits - $debits;
    }

    /**
     * Recursively fetch all sub-account IDs.
     */
    public function getAllChildIds(): array
    {
        $ids = [];
        foreach ($this->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->getAllChildIds());
        }
        return $ids;
    }
}
