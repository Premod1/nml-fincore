<?php

namespace Nml\FinCore\Services;

use Illuminate\Support\Facades\DB;
use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Enums\JvType;
use Nml\FinCore\Exceptions\AccountingException;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\FiscalYear;
use Nml\FinCore\Models\JournalEntry;

class FiscalYearClosingEngine
{
    /**
     * Close a fiscal year.
     * Resets Revenue and Expense balances for the year and transfers Net Profit/Loss to Retained Earnings.
     */
    public function closeFiscalYear(int $fiscalYearId, int $retainedEarningsAccountId, ?int $userId = null): ?JournalEntry
    {
        return DB::transaction(function () use ($fiscalYearId, $retainedEarningsAccountId, $userId) {
            $fiscalYear = FiscalYear::findOrFail($fiscalYearId);

            if ($fiscalYear->is_closed) {
                throw new AccountingException("Fiscal year '{$fiscalYear->name}' is already closed.");
            }

            // Ensure Retained Earnings account exists
            $retainedEarningsAccount = Account::findOrFail($retainedEarningsAccountId);

            $prefix = config('accounting.table_prefix', 'fincore_');

            // Retrieve all Revenue and Expense account net movements for this fiscal year
            $movements = DB::table($prefix . 'journal_entry_lines as lines')
                ->join($prefix . 'journal_entries as entries', 'lines.journal_entry_id', '=', 'entries.id')
                ->join($prefix . 'accounts as accounts', 'lines.account_id', '=', 'accounts.id')
                ->whereIn('accounts.type', [AccountType::REVENUE->value, AccountType::EXPENSE->value])
                ->where('entries.status', JvStatus::POSTED->value)
                ->where('entries.date', '>=', $fiscalYear->start_date->format('Y-m-d'))
                ->where('entries.date', '<=', $fiscalYear->end_date->format('Y-m-d'))
                ->select('lines.account_id', 'accounts.type', 'accounts.name')
                ->selectRaw("SUM(CASE WHEN lines.type = 'debit' THEN lines.amount ELSE 0 END) as total_debit")
                ->selectRaw("SUM(CASE WHEN lines.type = 'credit' THEN lines.amount ELSE 0 END) as total_credit")
                ->groupBy('lines.account_id', 'accounts.type', 'accounts.name')
                ->get();

            $closingLines = [];
            $totalClosingDebit = 0.0;
            $totalClosingCredit = 0.0;

            foreach ($movements as $mov) {
                $debits = (float) $mov->total_debit;
                $credits = (float) $mov->total_credit;
                $balance = 0.0;

                if ($mov->type === AccountType::REVENUE->value) {
                    // Revenue has normal Credit balance. Movement balance = Credits - Debits.
                    $balance = $credits - $debits;
                    if (abs($balance) > 0.005) {
                        if ($balance > 0) {
                            // To close positive Credit balance: Debit the account
                            $closingLines[] = [
                                'account_id'  => $mov->account_id,
                                'type'        => 'debit',
                                'amount'      => $balance,
                                'description' => "Close Revenue Account: {$mov->name}"
                            ];
                            $totalClosingDebit += $balance;
                        } else {
                            // Negative Credit balance: Credit the account
                            $closingLines[] = [
                                'account_id'  => $mov->account_id,
                                'type'        => 'credit',
                                'amount'      => abs($balance),
                                'description' => "Close Revenue Account (Negative Balance): {$mov->name}"
                            ];
                            $totalClosingCredit += abs($balance);
                        }
                    }
                } else {
                    // Expense has normal Debit balance. Movement balance = Debits - Credits.
                    $balance = $debits - $credits;
                    if (abs($balance) > 0.005) {
                        if ($balance > 0) {
                            // To close positive Debit balance: Credit the account
                            $closingLines[] = [
                                'account_id'  => $mov->account_id,
                                'type'        => 'credit',
                                'amount'      => $balance,
                                'description' => "Close Expense Account: {$mov->name}"
                            ];
                            $totalClosingCredit += $balance;
                        } else {
                            // Negative Debit balance: Debit the account
                            $closingLines[] = [
                                'account_id'  => $mov->account_id,
                                'type'        => 'debit',
                                'amount'      => abs($balance),
                                'description' => "Close Expense Account (Negative Balance): {$mov->name}"
                            ];
                            $totalClosingDebit += abs($balance);
                        }
                    }
                }
            }

            $entry = null;

            if (!empty($closingLines)) {
                // Determine net profit or loss to close to Retained Earnings
                $difference = $totalClosingDebit - $totalClosingCredit;

                if (abs($difference) > 0.005) {
                    if ($difference > 0) {
                        // Net Profit -> Credit Retained Earnings
                        $closingLines[] = [
                            'account_id'  => $retainedEarningsAccountId,
                            'type'        => 'credit',
                            'amount'      => abs($difference),
                            'description' => "Transfer Net Profit to Retained Earnings"
                        ];
                    } else {
                        // Net Loss -> Debit Retained Earnings
                        $closingLines[] = [
                            'account_id'  => $retainedEarningsAccountId,
                            'type'        => 'debit',
                            'amount'      => abs($difference),
                            'description' => "Transfer Net Loss to Retained Earnings"
                        ];
                    }
                }

                // Create the closing entry (last day of the fiscal year)
                $entry = (new LedgerEngine())->createJournalEntry([
                    'date'        => $fiscalYear->end_date->format('Y-m-d'),
                    'reference'   => 'YEC-' . $fiscalYear->name,
                    'type'        => JvType::CLOSING->value,
                    'description' => "Fiscal Year End Closing Entry for {$fiscalYear->name}",
                    'lines'       => $closingLines
                ]);

                // Post the entry by bypassing period lock for this specific closing transaction
                $entry->post(bypassPeriodLock: true, userId: $userId);
            }

            // Close the fiscal year and all associated periods
            $fiscalYear->update(['is_closed' => true]);
            $fiscalYear->periods()->update(['is_closed' => true]);

            return $entry;
        });
    }
}
