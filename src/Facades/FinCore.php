<?php

namespace Nml\FinCore\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Nml\FinCore\Models\JournalEntry createJournalEntry(array $data)
 * @method static array getGeneralLedger(?int $accountId = null, ?string $startDate = null, ?string $endDate = null, ?string $sbuCode = null, ?int $perPage = null, ?int $page = null)
 * @method static array getTrialBalance(?string $startDate = null, ?string $endDate = null, ?string $sbuCode = null)
 * @method static array getBalanceSheet(string $date, ?string $sbuCode = null)
 * @method static array getIncomeStatement(string $startDate, string $endDate, ?string $sbuCode = null)
 * @method static array getCashFlowStatement(string $startDate, string $endDate, ?string $sbuCode = null)
 * 
 * @see \Nml\FinCore\Services\LedgerEngine
 */
class FinCore extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'fincore';
    }
}
