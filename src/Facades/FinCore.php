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
 * @method static array getReceivablesAgeingReport(string $asOfDate, ?array $accountIds = null)
 * @method static array getPayablesAgeingReport(string $asOfDate, ?array $accountIds = null)
 * @method static \Nml\FinCore\Models\JournalEntry|null closeFiscalYear(int $fiscalYearId, int $retainedEarningsAccountId, ?int $userId = null)
 * @method static \Illuminate\Support\Collection getAuditHistory(int $journalEntryId)
 * @method static array calculateForexRevaluation(string $asOfDate, array $closingRates, ?string $sbuCode = null, ?array $accountIds = null)
 * @method static array revalueForexBalances(string $asOfDate, array $closingRates, bool $autoReverse = true, ?string $reversalDate = null, ?string $sbuCode = null, ?array $accountIds = null, ?int $gainAccountId = null, ?int $lossAccountId = null, ?int $userId = null)
 * @method static \Nml\FinCore\Models\JournalEntry reverseForexRevaluationEntry(\Nml\FinCore\Models\JournalEntry|int $entry, ?string $reversalDate = null, ?int $userId = null)
 * @method static \Nml\FinCore\Models\RecurringJournalEntry createRecurringEntry(array $data)
 * @method static \Nml\FinCore\Models\RecurringJournalEntry updateRecurringEntry(\Nml\FinCore\Models\RecurringJournalEntry|int $entry, array $data)
 * @method static array processDueRecurringEntries(?string $asOfDate = null, ?int $userId = null)
 * @method static \Nml\FinCore\Models\JournalEntry|null processRecurringEntry(\Nml\FinCore\Models\RecurringJournalEntry|int $recurringEntry, ?string $runDate = null, bool $force = false, ?int $userId = null)
 * @method static void setRoleChecker(?\Closure $checker)
 * @method static \Nml\FinCore\Models\JournalEntry submitForApproval(\Nml\FinCore\Models\JournalEntry|int $entry, ?int $userId = null)
 * @method static \Nml\FinCore\Models\JournalEntry approveJournalEntry(\Nml\FinCore\Models\JournalEntry|int $entry, int|string $approverId, ?string $comment = null, ?string $role = null)
 * @method static \Nml\FinCore\Models\JournalEntry rejectJournalEntry(\Nml\FinCore\Models\JournalEntry|int $entry, int|string $approverId, string $reason, ?string $role = null)
 * @method static bool canApproveJournalEntry(\Nml\FinCore\Models\JournalEntry|int $entry, int|string $userId, ?string $role = null)
 * @method static \Illuminate\Support\Collection getPendingApprovals(int|string|null $userId = null, ?string $role = null)
 * @method static \Illuminate\Support\Collection getApprovalHistory(\Nml\FinCore\Models\JournalEntry|int $entry)
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
