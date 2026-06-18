<?php

namespace Nml\FinCore\Services;

use Illuminate\Support\Facades\DB;
use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Exceptions\AccountingException;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\JournalEntry;
use Nml\FinCore\Models\JournalEntryLine;

class LedgerEngine
{
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

            /** @var JournalEntry $entry */
            $entry = JournalEntry::create([
                'entry_number'     => $entryNumber,
                'date'             => $date,
                'reference'        => $data['reference'] ?? null,
                'type'             => $data['type'] ?? 'general',
                'description'      => $data['description'] ?? null,
                'status'           => JvStatus::DRAFT,
                'sbu_code'         => $data['sbu_code'] ?? null,
                'journalable_type' => $data['journalable_type'] ?? null,
                'journalable_id'   => $data['journalable_id'] ?? null,
                'created_by'       => $data['created_by'] ?? null,
            ]);

            if (isset($data['lines']) && is_array($data['lines'])) {
                foreach ($data['lines'] as $line) {
                    $entry->lines()->create([
                        'account_id'  => $line['account_id'],
                        'type'        => $line['type'],
                        'amount'      => $line['amount'],
                        'description' => $line['description'] ?? null,
                    ]);
                }
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
        ?string $sbuCode = null
    ): array {
        $accountsQuery = Account::query()->where('is_active', true);
        if ($accountId) {
            $accountsQuery->where('id', $accountId);
        }

        $accounts = $accountsQuery->get();
        $report = [];

        foreach ($accounts as $account) {
            // 1. Calculate Opening Balance (posted balance before start date)
            $openingBalance = 0.0;
            if ($startDate) {
                $openingBalance = $account->getCurrentBalance(
                    includeChildren: false,
                    sbuCode: $sbuCode,
                    startDate: null,
                    endDate: date('Y-m-d', strtotime($startDate . ' -1 day'))
                );
            }

            // 2. Fetch lines within period
            $linesQuery = JournalEntryLine::query()
                ->where('account_id', $account->id)
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

            $periodDebits = (float) (clone $linesQuery)->where('type', 'debit')->sum('amount');
            $periodCredits = (float) (clone $linesQuery)->where('type', 'credit')->sum('amount');

            // 3. Calculate Running Balance for details
            $lines = $linesQuery->with('journalEntry')->get();
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
                    'line_id'         => $line->id,
                    'date'            => $line->journalEntry->date->format('Y-m-d'),
                    'entry_number'    => $line->journalEntry->entry_number,
                    'reference'       => $line->journalEntry->reference,
                    'description'     => $line->description ?? $line->journalEntry->description,
                    'type'            => $line->type,
                    'amount'          => $amt,
                    'running_balance' => $runningBalance,
                ];
            }

            $closingBalance = $account->getCurrentBalance(false, $sbuCode, null, $endDate);

            $report[] = [
                'account'          => $account,
                'opening_balance'  => $openingBalance,
                'period_debits'    => $periodDebits,
                'period_credits'   => $periodCredits,
                'closing_balance'  => $closingBalance,
                'entries'          => $formattedEntries,
            ];
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
        $report = [];
        $totalDebits = 0.0;
        $totalCredits = 0.0;

        foreach ($accounts as $account) {
            $balance = $account->getCurrentBalance(false, $sbuCode, $startDate, $endDate);
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
        
        $assets = [];
        $liabilities = [];
        $equity = [];

        $totalAssets = 0.0;
        $totalLiabilities = 0.0;
        $totalEquity = 0.0;

        foreach ($accounts as $account) {
            $balance = $account->getCurrentBalance(false, $sbuCode, null, $date);
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
        
        $revenue = [];
        $expenses = [];

        $totalRevenue = 0.0;
        $totalExpenses = 0.0;

        foreach ($accounts as $account) {
            $balance = $account->getCurrentBalance(false, $sbuCode, $startDate, $endDate);
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
}
