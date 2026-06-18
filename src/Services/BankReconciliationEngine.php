<?php

namespace Nml\FinCore\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Exceptions\AccountingException;
use Nml\FinCore\Models\BankReconciliation;
use Nml\FinCore\Models\JournalEntryLine;

class BankReconciliationEngine
{
    /**
     * Create a new Bank Reconciliation statement record.
     */
    public function createReconciliation(
        int $accountId,
        string $statementDate,
        float $openingBalance,
        float $closingBalance
    ): BankReconciliation {
        return BankReconciliation::create([
            'account_id'      => $accountId,
            'statement_date'  => $statementDate,
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'is_finalized'    => false,
        ]);
    }

    /**
     * Get all unreconciled posted ledger lines for a bank account.
     */
    public function getUnreconciledLines(int $accountId, string $endDate): Collection
    {
        return JournalEntryLine::where('account_id', $accountId)
            ->whereNull('cleared_at')
            ->whereNull('bank_reconciliation_id')
            ->whereHas('journalEntry', function ($query) use ($endDate) {
                $query->where('status', JvStatus::POSTED)
                    ->where('date', '<=', $endDate);
            })
            ->with('journalEntry')
            ->get();
    }

    /**
     * Automatically match statement transactions with unreconciled ledger lines.
     * $statementTransactions format:
     * [
     *   ['date' => '2026-06-10', 'amount' => 5000.00, 'type' => 'debit', 'reference' => 'TXN-1'],
     *   ...
     * ]
     */
    public function autoMatchStatementTransactions(int $reconciliationId, array $statementTransactions): array
    {
        return DB::transaction(function () use ($reconciliationId, $statementTransactions) {
            $reconciliation = BankReconciliation::findOrFail($reconciliationId);
            
            if ($reconciliation->is_finalized) {
                throw new AccountingException("Cannot match transactions on a finalized reconciliation.");
            }

            $unreconciledLines = $this->getUnreconciledLines(
                $reconciliation->account_id,
                $reconciliation->statement_date->format('Y-m-d')
            )->keyBy('id');

            $matched = [];
            $unmatched = [];

            foreach ($statementTransactions as $tx) {
                $txDate = strtotime($tx['date']);
                $txAmount = (float) $tx['amount'];
                $txType = $tx['type']; // 'debit' or 'credit'

                $matchedLine = null;

                // Pass 1: Try exact match within ±3 days
                foreach ($unreconciledLines as $line) {
                    if ($line->type === $txType && abs($line->amount - $txAmount) < 0.005) {
                        $lineDate = strtotime($line->journalEntry->date->format('Y-m-d'));
                        $daysDiff = abs($lineDate - $txDate) / 86400;
                        if ($daysDiff <= 3) {
                            $matchedLine = $line;
                            break;
                        }
                    }
                }

                // Pass 2: Try fuzzy match within ±7 days
                if (!$matchedLine) {
                    foreach ($unreconciledLines as $line) {
                        if ($line->type === $txType && abs($line->amount - $txAmount) < 0.005) {
                            $lineDate = strtotime($line->journalEntry->date->format('Y-m-d'));
                            $daysDiff = abs($lineDate - $txDate) / 86400;
                            if ($daysDiff <= 7) {
                                $matchedLine = $line;
                                break;
                            }
                        }
                    }
                }

                if ($matchedLine) {
                    // Reconcile and clear the line
                    $matchedLine->update([
                        'bank_reconciliation_id' => $reconciliation->id,
                        'cleared_at'             => date('Y-m-d H:i:s', $txDate),
                    ]);

                    // Remove from list to avoid double matching
                    $unreconciledLines->forget($matchedLine->id);

                    $matched[] = [
                        'statement_transaction' => $tx,
                        'journal_entry_line_id' => $matchedLine->id,
                        'journal_entry_number'  => $matchedLine->journalEntry->entry_number,
                    ];
                } else {
                    $unmatched[] = $tx;
                }
            }

            return [
                'matched'   => $matched,
                'unmatched' => $unmatched,
            ];
        });
    }

    /**
     * Manually link a ledger line to the reconciliation statement.
     */
    public function manuallyClearLine(int $reconciliationId, int $lineId, string $clearedAt): JournalEntryLine
    {
        $reconciliation = BankReconciliation::findOrFail($reconciliationId);
        
        if ($reconciliation->is_finalized) {
            throw new AccountingException("Cannot clear lines on a finalized reconciliation.");
        }

        $line = JournalEntryLine::findOrFail($lineId);
        
        if ($line->cleared_at || $line->bank_reconciliation_id) {
            throw new AccountingException("Journal entry line is already cleared or reconciled.");
        }

        $line->update([
            'bank_reconciliation_id' => $reconciliation->id,
            'cleared_at'             => $clearedAt,
        ]);

        return $line;
    }

    /**
     * Unlink a ledger line from reconciliation.
     */
    public function unclearLine(int $lineId): JournalEntryLine
    {
        $line = JournalEntryLine::findOrFail($lineId);
        
        if ($line->bankReconciliation && $line->bankReconciliation->is_finalized) {
            throw new AccountingException("Cannot unclear a line associated with a finalized reconciliation.");
        }

        $line->update([
            'bank_reconciliation_id' => null,
            'cleared_at'             => null,
        ]);

        return $line;
    }

    /**
     * Finalize reconciliation by validating statement balances.
     */
    public function finalizeReconciliation(int $reconciliationId): BankReconciliation
    {
        return DB::transaction(function () use ($reconciliationId) {
            $reconciliation = BankReconciliation::findOrFail($reconciliationId);

            if ($reconciliation->is_finalized) {
                return $reconciliation;
            }

            $clearedBalance = $reconciliation->calculateClearedBalance();
            $difference = abs($clearedBalance - $reconciliation->closing_balance);
            
            $tolerance = 0.005; // rounding tolerance

            if ($difference > $tolerance) {
                throw new AccountingException(
                    "Reconciliation balance mismatch. Cleared Balance: {$clearedBalance}, Statement Closing Balance: {$reconciliation->closing_balance}. Difference: {$difference}"
                );
            }

            $reconciliation->update([
                'is_finalized'  => true,
                'reconciled_at' => now(),
            ]);

            return $reconciliation;
        });
    }
}
