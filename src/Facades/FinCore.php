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
 * @method static float calculateMonthlyDepreciation(\Nml\FinCore\Models\FixedAsset $asset, string $date)
 * @method static \Nml\FinCore\Models\JournalEntry|null postDepreciationForAsset(int $assetId, string $date)
 * @method static array postDepreciationForAllActiveAssets(string $date)
 * @method static \Nml\FinCore\Models\Budget setMonthlyBudget(int $accountId, int $year, int $month, float $amount, ?string $sbuCode = null)
 * @method static array getBudgetVarianceReport(int $year, int $month, ?string $sbuCode = null)
 * @method static \Nml\FinCore\Models\BankReconciliation createReconciliation(int $accountId, string $statementDate, float $openingBalance, float $closingBalance)
 * @method static \Illuminate\Support\Collection getUnreconciledLines(int $accountId, string $endDate)
 * @method static array autoMatchStatementTransactions(int $reconciliationId, array $statementTransactions)
 * @method static \Nml\FinCore\Models\JournalEntryLine manuallyClearLine(int $reconciliationId, int $lineId, string $clearedAt)
 * @method static \Nml\FinCore\Models\JournalEntryLine unclearLine(int $lineId)
 * @method static \Nml\FinCore\Models\BankReconciliation finalizeReconciliation(int $reconciliationId)
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
