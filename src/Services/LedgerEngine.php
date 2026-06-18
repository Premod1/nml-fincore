<?php

namespace Nml\FinCore\Services;

use Illuminate\Support\Facades\DB;
use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Exceptions\AccountingException;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\JournalEntry;
use Nml\FinCore\Models\JournalEntryLine;
use Nml\FinCore\Models\Tax;

class LedgerEngine
{
    /**
     * Fetch balances for multiple accounts in a single optimized query.
     * Returns an array mapping account_id => balance (float).
     */
    public function getBalancesForAccounts(
        array $accountIds,
        ?string $sbuCode = null,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        if (empty($accountIds)) {
            return [];
        }

        $prefix = config('accounting.table_prefix', 'fincore_');

        $query = DB::table($prefix . 'journal_entry_lines as lines')
            ->join($prefix . 'journal_entries as entries', 'lines.journal_entry_id', '=', 'entries.id')
            ->whereIn('lines.account_id', $accountIds)
            ->where('entries.status', JvStatus::POSTED->value);

        if ($sbuCode) {
            $query->where('entries.sbu_code', $sbuCode);
        }
        if ($startDate) {
            $query->where('entries.date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('entries.date', '<=', $endDate);
        }

        $results = $query->select('lines.account_id')
            ->selectRaw("SUM(CASE WHEN lines.type = 'debit' THEN lines.amount ELSE 0 END) as total_debit")
            ->selectRaw("SUM(CASE WHEN lines.type = 'credit' THEN lines.amount ELSE 0 END) as total_credit")
            ->groupBy('lines.account_id')
            ->get();

        $balances = [];
        foreach ($accountIds as $id) {
            $balances[$id] = 0.0;
        }

        $accounts = Account::whereIn('id', $accountIds)->get()->keyBy('id');

        foreach ($results as $row) {
            $accId = $row->account_id;
            $account = $accounts->get($accId);
            if ($account) {
                $debits = (float) $row->total_debit;
                $credits = (float) $row->total_credit;
                if ($account->hasNormalDebitBalance()) {
                    $balances[$accId] = $debits - $credits;
                } else {
                    $balances[$accId] = $credits - $debits;
                }
            }
        }

        return $balances;
    }

    /**
     * Create a new Journal Entry with its lines.
     */
    public function createJournalEntry(array $data): JournalEntry
    {
        return DB::transaction(function () use ($data) {
            $date = $data['date'] ?? now()->format('Y-m-d');
            $year = date('Y', strtotime($date));

            // Generate entry number (JE-YYYY-00001)
            $count = JournalEntry::whereYear('date', $year)->count() + 1;
            $entryNumber = 'JE-' . $year . '-' . str_pad($count, 5, '0', STR_PAD_LEFT);

            $currency = $data['currency'] ?? config('accounting.currency', 'LKR');
            $exchangeRate = (float) ($data['exchange_rate'] ?? 1.0);
            $isForeign = ($currency !== config('accounting.currency', 'LKR'));

            /** @var JournalEntry $entry */
            $entry = JournalEntry::create([
                'entry_number'     => $entryNumber,
                'date'             => $date,
                'reference'        => $data['reference'] ?? null,
                'type'             => $data['type'] ?? 'general',
                'description'      => $data['description'] ?? null,
                'status'           => JvStatus::DRAFT,
                'currency'         => $currency,
                'exchange_rate'    => $exchangeRate,
                'sbu_code'         => $data['sbu_code'] ?? null,
                'journalable_type' => $data['journalable_type'] ?? null,
                'journalable_id'   => $data['journalable_id'] ?? null,
                'created_by'       => $data['created_by'] ?? null,
            ]);

            $processedLines = [];

            if (isset($data['lines']) && is_array($data['lines'])) {
                foreach ($data['lines'] as $line) {
                    $fcAmount = isset($line['fc_amount']) ? (float) $line['fc_amount'] : null;
                    $amount = isset($line['amount']) ? (float) $line['amount'] : null;

                    if ($isForeign) {
                        if ($fcAmount !== null && $amount === null) {
                            $amount = round($fcAmount * $exchangeRate, 4);
                        } elseif ($fcAmount === null && $amount !== null) {
                            $fcAmount = round($amount / $exchangeRate, 4);
                        } elseif ($fcAmount === null && $amount === null) {
                            $fcAmount = 0.0;
                            $amount = 0.0;
                        }
                    } else {
                        if ($amount === null) {
                            $amount = $fcAmount !== null ? $fcAmount : 0.0;
                        }
                        $fcAmount = $amount;
                    }

                    $taxId = $line['tax_id'] ?? null;
                    $taxBehavior = $line['tax_behavior'] ?? 'exclusive';

                    if ($taxId) {
                        $tax = Tax::find($taxId);
                        if ($tax && $tax->is_active) {
                            $rate = (float) $tax->rate;
                            if ($taxBehavior === 'inclusive') {
                                $fcTaxAmount = round($fcAmount - ($fcAmount / (1 + $rate / 100)), 4);
                                $taxAmount = round($amount - ($amount / (1 + $rate / 100)), 4);

                                // Adjust base line
                                $fcAmount = round($fcAmount - $fcTaxAmount, 4);
                                $amount = round($amount - $taxAmount, 4);
                            } else {
                                // Exclusive
                                $fcTaxAmount = round($fcAmount * ($rate / 100), 4);
                                $taxAmount = round($amount * ($rate / 100), 4);
                            }

                            // Push base line
                            $processedLines[] = [
                                'account_id'       => $line['account_id'],
                                'type'             => $line['type'],
                                'amount'           => $amount,
                                'fc_amount'        => $fcAmount,
                                'tax_id'           => $taxId,
                                'description'      => $line['description'] ?? null,
                                'due_date'         => $line['due_date'] ?? null,
                                'partnerable_type' => $line['partnerable_type'] ?? null,
                                'partnerable_id'   => $line['partnerable_id'] ?? null,
                            ];

                            // Push automatic tax line
                            $processedLines[] = [
                                'account_id'       => $tax->account_id,
                                'type'             => $line['type'],
                                'amount'           => $taxAmount,
                                'fc_amount'        => $fcTaxAmount,
                                'tax_id'           => $taxId,
                                'description'      => "Tax (" . $tax->name . ") calculated for " . ($line['description'] ?? 'base transaction'),
                                'due_date'         => $line['due_date'] ?? null,
                                'partnerable_type' => $line['partnerable_type'] ?? null,
                                'partnerable_id'   => $line['partnerable_id'] ?? null,
                            ];

                            continue;
                        }
                    }

                    // Push standard line
                    $processedLines[] = [
                        'account_id'       => $line['account_id'],
                        'type'             => $line['type'],
                        'amount'           => $amount,
                        'fc_amount'        => $fcAmount,
                        'tax_id'           => null,
                        'description'      => $line['description'] ?? null,
                        'due_date'         => $line['due_date'] ?? null,
                        'partnerable_type' => $line['partnerable_type'] ?? null,
                        'partnerable_id'   => $line['partnerable_id'] ?? null,
                    ];
                }
            }

            foreach ($processedLines as $processedLine) {
                $entry->lines()->create($processedLine);
            }

            return $entry;
        });
    }

    /**
     * Generate General Ledger report.
     */
    public function getGeneralLedger(
        ?int $accountId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $sbuCode = null,
        ?int $perPage = null,
        ?int $page = null
    ): array {
        $accountsQuery = Account::query()->where('is_active', true);
        if ($accountId) {
            $accountsQuery->where('id', $accountId);
        }

        $accounts = $accountsQuery->get();
        $accountIds = $accounts->pluck('id')->toArray();
        $dayBeforeStart = $startDate ? date('Y-m-d', strtotime($startDate . ' -1 day')) : null;

        // Bulk fetch opening and closing balances
        $openingBalances = [];
        if ($startDate) {
            $openingBalances = $this->getBalancesForAccounts($accountIds, $sbuCode, null, $dayBeforeStart);
        }
        $closingBalances = $this->getBalancesForAccounts($accountIds, $sbuCode, null, $endDate);

        // Bulk fetch period debits/credits to eliminate loop subqueries
        $prefix = config('accounting.table_prefix', 'fincore_');
        $periodSumsQuery = DB::table($prefix . 'journal_entry_lines as lines')
            ->join($prefix . 'journal_entries as entries', 'lines.journal_entry_id', '=', 'entries.id')
            ->whereIn('lines.account_id', $accountIds)
            ->where('entries.status', JvStatus::POSTED->value);

        if ($sbuCode) {
            $periodSumsQuery->where('entries.sbu_code', $sbuCode);
        }
        if ($startDate) {
            $periodSumsQuery->where('entries.date', '>=', $startDate);
        }
        if ($endDate) {
            $periodSumsQuery->where('entries.date', '<=', $endDate);
        }

        $periodSums = $periodSumsQuery->select('lines.account_id')
            ->selectRaw("SUM(CASE WHEN lines.type = 'debit' THEN lines.amount ELSE 0 END) as period_debit")
            ->selectRaw("SUM(CASE WHEN lines.type = 'credit' THEN lines.amount ELSE 0 END) as period_credit")
            ->groupBy('lines.account_id')
            ->get()
            ->keyBy('account_id');

        $report = [];

        foreach ($accounts as $account) {
            $openingBalance = $openingBalances[$account->id] ?? 0.0;
            $closingBalance = $closingBalances[$account->id] ?? 0.0;

            $sums = $periodSums->get($account->id);
            $periodDebits = $sums ? (float) $sums->period_debit : 0.0;
            $periodCredits = $sums ? (float) $sums->period_credit : 0.0;

            // Fetch lines using Query Builder (stdClass) to avoid Eloquent OOM
            $linesQuery = DB::table($prefix . 'journal_entry_lines as lines')
                ->join($prefix . 'journal_entries as entries', 'lines.journal_entry_id', '=', 'entries.id')
                ->where('lines.account_id', $account->id)
                ->where('entries.status', JvStatus::POSTED->value)
                ->select(
                    'lines.id as line_id',
                    'lines.type',
                    'lines.amount',
                    'lines.description as line_description',
                    'entries.date',
                    'entries.entry_number',
                    'entries.reference',
                    'entries.description as entry_description'
                );

            if ($sbuCode) {
                $linesQuery->where('entries.sbu_code', $sbuCode);
            }
            if ($startDate) {
                $linesQuery->where('entries.date', '>=', $startDate);
            }
            if ($endDate) {
                $linesQuery->where('entries.date', '<=', $endDate);
            }

            $linesQuery->orderBy('entries.date')->orderBy('entries.entry_number');

            $totalCount = 0;
            if ($perPage) {
                $totalCount = $linesQuery->count();
                $page = $page ?: 1;
                $linesQuery->skip(($page - 1) * $perPage)->take($perPage);
            }

            $lines = $linesQuery->get();
            $runningBalance = $openingBalance;
            $formattedEntries = [];

            foreach ($lines as $line) {
                $amt = (float) $line->amount;
                if ($account->hasNormalDebitBalance()) {
                    $runningBalance += ($line->type === 'debit') ? $amt : -$amt;
                } else {
                    $runningBalance += ($line->type === 'credit') ? $amt : -$amt;
                }

                $formattedEntries[] = [
                    'line_id'         => $line->line_id,
                    'date'            => is_string($line->date) ? $line->date : $line->date->format('Y-m-d'),
                    'entry_number'    => $line->entry_number,
                    'reference'       => $line->reference,
                    'description'     => $line->line_description ?? $line->entry_description,
                    'type'            => $line->type,
                    'amount'          => $amt,
                    'running_balance' => $runningBalance,
                ];
            }

            $reportItem = [
                'account'          => $account,
                'opening_balance'  => $openingBalance,
                'period_debits'    => $periodDebits,
                'period_credits'   => $periodCredits,
                'closing_balance'  => $closingBalance,
                'entries'          => $formattedEntries,
            ];

            if ($perPage) {
                $reportItem['pagination'] = [
                    'total' => $totalCount,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => (int) ceil($totalCount / $perPage),
                ];
            }

            $report[] = $reportItem;
        }

        return ['accounts' => $report];
    }

    /**
     * Generate Trial Balance.
     */
    public function getTrialBalance(
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $sbuCode = null
    ): array {
        $accounts = Account::where('is_active', true)->get();
        $accountIds = $accounts->pluck('id')->toArray();
        $balances = $this->getBalancesForAccounts($accountIds, $sbuCode, $startDate, $endDate);

        $report = [];
        $totalDebits = 0.0;
        $totalCredits = 0.0;

        foreach ($accounts as $account) {
            $balance = $balances[$account->id] ?? 0.0;
            if ($balance == 0.0) {
                continue;
            }

            $debit = 0.0;
            $credit = 0.0;

            if ($account->hasNormalDebitBalance()) {
                if ($balance > 0) {
                    $debit = $balance;
                } else {
                    $credit = abs($balance);
                }
            } else {
                if ($balance > 0) {
                    $credit = $balance;
                } else {
                    $debit = abs($balance);
                }
            }

            $totalDebits += $debit;
            $totalCredits += $credit;

            $report[] = [
                'account' => $account,
                'debit'   => $debit,
                'credit'  => $credit,
                'balance' => $balance,
            ];
        }

        $tolerance = (float) config('accounting.rounding_tolerance', 0.005);
        $isBalanced = abs($totalDebits - $totalCredits) <= $tolerance;

        return [
            'accounts'      => $report,
            'total_debits'  => $totalDebits,
            'total_credits' => $totalCredits,
            'is_balanced'   => $isBalanced,
        ];
    }

    /**
     * Generate Balance Sheet.
     */
    public function getBalanceSheet(string $date, ?string $sbuCode = null): array
    {
        $accounts = Account::where('is_active', true)->get();
        $accountIds = $accounts->pluck('id')->toArray();
        $balances = $this->getBalancesForAccounts($accountIds, $sbuCode, null, $date);
        
        $assets = [];
        $liabilities = [];
        $equity = [];

        $totalAssets = 0.0;
        $totalLiabilities = 0.0;
        $totalEquity = 0.0;

        foreach ($accounts as $account) {
            $balance = $balances[$account->id] ?? 0.0;
            if ($balance == 0.0) {
                continue;
            }

            $item = [
                'account' => $account,
                'balance' => $balance,
            ];

            if ($account->type === AccountType::ASSET) {
                $assets[] = $item;
                $totalAssets += $balance;
            } elseif ($account->type === AccountType::LIABILITY) {
                $liabilities[] = $item;
                $totalLiabilities += $balance;
            } elseif ($account->type === AccountType::EQUITY) {
                $equity[] = $item;
                $totalEquity += $balance;
            }
        }

        // Include retained earnings / current year profit rollup dynamically if not closed
        $tolerance = (float) config('accounting.rounding_tolerance', 0.005);
        $isBalanced = abs($totalAssets - ($totalLiabilities + $totalEquity)) <= $tolerance;

        return [
            'as_of_date'  => $date,
            'assets'      => ['accounts' => $assets, 'total' => $totalAssets],
            'liabilities' => ['accounts' => $liabilities, 'total' => $totalLiabilities],
            'equity'      => ['accounts' => $equity, 'total' => $totalEquity],
            'is_balanced' => $isBalanced,
        ];
    }

    /**
     * Generate Income Statement (P&L).
     */
    public function getIncomeStatement(string $startDate, string $endDate, ?string $sbuCode = null): array
    {
        $accounts = Account::where('is_active', true)->get();
        $accountIds = $accounts->pluck('id')->toArray();
        $balances = $this->getBalancesForAccounts($accountIds, $sbuCode, $startDate, $endDate);
        
        $revenue = [];
        $expenses = [];

        $totalRevenue = 0.0;
        $totalExpenses = 0.0;

        foreach ($accounts as $account) {
            $balance = $balances[$account->id] ?? 0.0;
            if ($balance == 0.0) {
                continue;
            }

            $item = [
                'account' => $account,
                'balance' => $balance,
            ];

            if ($account->type === AccountType::REVENUE) {
                $revenue[] = $item;
                $totalRevenue += $balance;
            } elseif ($account->type === AccountType::EXPENSE) {
                $expenses[] = $item;
                $totalExpenses += $balance;
            }
        }

        $netIncome = $totalRevenue - $totalExpenses;

        return [
            'start_date'   => $startDate,
            'end_date'     => $endDate,
            'revenue'      => ['accounts' => $revenue, 'total' => $totalRevenue],
            'expenses'     => ['accounts' => $expenses, 'total' => $totalExpenses],
            'gross_profit' => $netIncome, // Simplified without COGS sub-breakdown
            'net_income'   => $netIncome,
        ];
    }

    /**
     * Generate Cash Flow Statement (Indirect Method).
     */
    public function getCashFlowStatement(string $startDate, string $endDate, ?string $sbuCode = null): array
    {
        $cashCodes = config('accounting.cash_account_codes', ['1000', '1100']);
        $financingCodes = config('accounting.financing_account_codes', ['2400', '2500', '3000']);
        $nonCashExpenseCodes = config('accounting.non_cash_expense_codes', ['5500']);

        // 1. Net Income from Income Statement
        $incomeStatement = $this->getIncomeStatement($startDate, $endDate, $sbuCode);
        $netIncome = $incomeStatement['net_income'];

        // Helper to calculate balance changes
        $dayBeforeStart = date('Y-m-d', strtotime($startDate . ' -1 day'));

        // Load all active accounts
        $accounts = Account::where('is_active', true)->get();
        $accountIds = $accounts->pluck('id')->toArray();

        // Optimized bulk queries for opening and closing balances
        $balancesStart = $this->getBalancesForAccounts($accountIds, $sbuCode, null, $dayBeforeStart);
        $balancesEnd = $this->getBalancesForAccounts($accountIds, $sbuCode, null, $endDate);
        
        // Also get period balances for non-cash expenses (like depreciation)
        $balancesPeriod = $this->getBalancesForAccounts($accountIds, $sbuCode, $startDate, $endDate);

        $operatingAdjustments = [];
        $investingAdjustments = [];
        $financingAdjustments = [];

        $totalOperating = $netIncome; // Starting with Net Income
        $totalInvesting = 0.0;
        $totalFinancing = 0.0;

        foreach ($accounts as $account) {
            $code = $account->code;

            // Skip cash accounts
            if (in_array($code, $cashCodes)) {
                continue;
            }

            // Calculate change in balance: Balance(End) - Balance(Before Start)
            $balStart = $balancesStart[$account->id] ?? 0.0;
            $balEnd = $balancesEnd[$account->id] ?? 0.0;
            $change = $balEnd - $balStart;

            // Classification of adjustments:
            if ($account->type === AccountType::EXPENSE && in_array($code, $nonCashExpenseCodes)) {
                // Non-cash expense (e.g. depreciation):
                // We add back the period expense (not the balance change)
                $periodExpense = $balancesPeriod[$account->id] ?? 0.0;
                if ($periodExpense != 0.0) {
                    $operatingAdjustments[] = [
                        'account_id' => $account->id,
                        'code' => $code,
                        'name' => $account->name,
                        'type' => 'non_cash_expense',
                        'change' => $periodExpense,
                        'impact' => $periodExpense, // Add back
                    ];
                    $totalOperating += $periodExpense;
                }
            } elseif ($account->type === AccountType::ASSET) {
                if ($account->subtype === 'current_asset') {
                    // Operating Asset adjustment: -(Change)
                    if ($change != 0.0) {
                        $impact = -$change;
                        $operatingAdjustments[] = [
                            'account_id' => $account->id,
                            'code' => $code,
                            'name' => $account->name,
                            'type' => 'operating_asset',
                            'change' => $change,
                            'impact' => $impact,
                        ];
                        $totalOperating += $impact;
                    }
                } elseif ($account->subtype === 'fixed_asset' || $account->subtype === 'non_current_asset') {
                    // Investing Asset adjustment: -(Change)
                    if ($change != 0.0) {
                        $impact = -$change;
                        $investingAdjustments[] = [
                            'account_id' => $account->id,
                            'code' => $code,
                            'name' => $account->name,
                            'type' => 'investing_asset',
                            'change' => $change,
                            'impact' => $impact,
                        ];
                        $totalInvesting += $impact;
                    }
                }
            } elseif ($account->type === AccountType::LIABILITY) {
                if (in_array($code, $financingCodes) || $account->subtype === 'non_current_liability') {
                    // Financing Liability adjustment: +(Change)
                    if ($change != 0.0) {
                        $financingAdjustments[] = [
                            'account_id' => $account->id,
                            'code' => $code,
                            'name' => $account->name,
                            'type' => 'financing_liability',
                            'change' => $change,
                            'impact' => $change,
                        ];
                        $totalFinancing += $change;
                    }
                } elseif ($account->subtype === 'current_liability') {
                    // Operating Liability adjustment: +(Change)
                    if ($change != 0.0) {
                        $operatingAdjustments[] = [
                            'account_id' => $account->id,
                            'code' => $code,
                            'name' => $account->name,
                            'type' => 'operating_liability',
                            'change' => $change,
                            'impact' => $change,
                        ];
                        $totalOperating += $change;
                    }
                }
            } elseif ($account->type === AccountType::EQUITY) {
                // Exclude Retained Earnings (3100) and Income Summary (3900)
                if ($code !== '3100' && $code !== '3900' && $change != 0.0) {
                    $financingAdjustments[] = [
                        'account_id' => $account->id,
                        'code' => $code,
                        'name' => $account->name,
                        'type' => 'equity',
                        'change' => $change,
                        'impact' => $change,
                    ];
                    $totalFinancing += $change;
                }
            }
        }

        // 4. Calculate beginning and ending cash
        $beginningCash = 0.0;
        $endingCash = 0.0;
        $cashDetails = [];

        $cashAccounts = Account::whereIn('code', $cashCodes)->get();
        foreach ($cashAccounts as $cashAccount) {
            $startBal = $balancesStart[$cashAccount->id] ?? 0.0;
            $endBal = $balancesEnd[$cashAccount->id] ?? 0.0;
            $beginningCash += $startBal;
            $endingCash += $endBal;

            $cashDetails[] = [
                'account_id' => $cashAccount->id,
                'code' => $cashAccount->code,
                'name' => $cashAccount->name,
                'beginning_balance' => $startBal,
                'ending_balance' => $endBal,
                'change' => $endBal - $startBal,
            ];
        }

        $netIncreaseDecrease = $totalOperating + $totalInvesting + $totalFinancing;

        $tolerance = (float) config('accounting.rounding_tolerance', 0.005);
        $reconciledDiff = abs(($beginningCash + $netIncreaseDecrease) - $endingCash);
        $isReconciled = $reconciledDiff <= $tolerance;

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'net_income' => $netIncome,
            'operating_activities' => [
                'adjustments' => $operatingAdjustments,
                'total' => $totalOperating,
            ],
            'investing_activities' => [
                'adjustments' => $investingAdjustments,
                'total' => $totalInvesting,
            ],
            'financing_activities' => [
                'adjustments' => $financingAdjustments,
                'total' => $totalFinancing,
            ],
            'net_increase_decrease' => $netIncreaseDecrease,
            'beginning_cash' => $beginningCash,
            'ending_cash' => $endingCash,
            'cash_details' => $cashDetails,
            'is_reconciled' => $isReconciled,
            'reconciled_difference' => $reconciledDiff,
        ];
    }

    /**
     * Calculate depreciation amount for a specific asset for one month.
     */
    public function calculateMonthlyDepreciation(\Nml\FinCore\Models\FixedAsset $asset, string $date): float
    {
        return (new DepreciationEngine())->calculateMonthlyDepreciation($asset, $date);
    }

    /**
     * Calculate and post depreciation for a single asset.
     */
    public function postDepreciationForAsset(int $assetId, string $date): ?\Nml\FinCore\Models\JournalEntry
    {
        return (new DepreciationEngine())->postDepreciationForAsset($assetId, $date);
    }

    /**
     * Post depreciation for all active fixed assets.
     */
    public function postDepreciationForAllActiveAssets(string $date): array
    {
        return (new DepreciationEngine())->postDepreciationForAllActiveAssets($date);
    }

    /**
     * Set or update monthly budget target for an account.
     */
    public function setMonthlyBudget(
        int $accountId,
        int $year,
        int $month,
        float $amount,
        ?string $sbuCode = null
    ): \Nml\FinCore\Models\Budget {
        return (new BudgetEngine())->setMonthlyBudget($accountId, $year, $month, $amount, $sbuCode);
    }

    /**
     * Get Budget vs Actual variance report for a specific month.
     */
    public function getBudgetVarianceReport(int $year, int $month, ?string $sbuCode = null): array
    {
        return (new BudgetEngine())->getBudgetVarianceReport($year, $month, $sbuCode);
    }

    /**
     * Create a new Bank Reconciliation statement record.
     */
    public function createReconciliation(
        int $accountId,
        string $statementDate,
        float $openingBalance,
        float $closingBalance
    ): \Nml\FinCore\Models\BankReconciliation {
        return (new BankReconciliationEngine())->createReconciliation($accountId, $statementDate, $openingBalance, $closingBalance);
    }

    /**
     * Get all unreconciled posted ledger lines for a bank account.
     */
    public function getUnreconciledLines(int $accountId, string $endDate): \Illuminate\Support\Collection
    {
        return (new BankReconciliationEngine())->getUnreconciledLines($accountId, $endDate);
    }

    /**
     * Automatically match statement transactions with unreconciled ledger lines.
     */
    public function autoMatchStatementTransactions(int $reconciliationId, array $statementTransactions): array
    {
        return (new BankReconciliationEngine())->autoMatchStatementTransactions($reconciliationId, $statementTransactions);
    }

    /**
     * Manually link a ledger line to the reconciliation statement.
     */
    public function manuallyClearLine(int $reconciliationId, int $lineId, string $clearedAt): \Nml\FinCore\Models\JournalEntryLine
    {
        return (new BankReconciliationEngine())->manuallyClearLine($reconciliationId, $lineId, $clearedAt);
    }

    /**
     * Unlink a ledger line from reconciliation.
     */
    public function unclearLine(int $lineId): \Nml\FinCore\Models\JournalEntryLine
    {
        return (new BankReconciliationEngine())->unclearLine($lineId);
    }

    /**
     * Finalize reconciliation by validating statement balances.
     */
    public function finalizeReconciliation(int $reconciliationId): \Nml\FinCore\Models\BankReconciliation
    {
        return (new BankReconciliationEngine())->finalizeReconciliation($reconciliationId);
    }

    /**
     * Generate an ageing report for Accounts Receivable.
     */
    public function getReceivablesAgeingReport(string $asOfDate): array
    {
        return (new PartnerAgeingEngine())->getReceivablesAgeingReport($asOfDate);
    }

    /**
     * Generate an ageing report for Accounts Payable.
     */
    public function getPayablesAgeingReport(string $asOfDate): array
    {
        return (new PartnerAgeingEngine())->getPayablesAgeingReport($asOfDate);
    }
}
