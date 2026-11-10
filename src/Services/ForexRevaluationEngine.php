<?php

namespace Nml\FinCore\Services;

use Illuminate\Support\Facades\DB;
use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Enums\JvType;
use Nml\FinCore\Exceptions\AccountingException;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\FiscalPeriod;
use Nml\FinCore\Models\JournalEntry;

class ForexRevaluationEngine
{
    /**
     * Preview / calculate period-end unrealized foreign exchange revaluation without creating entries.
     *
     * @param string $asOfDate
     * @param array<string, float> $closingRates Associative array of currency => closing_exchange_rate
     * @param string|null $sbuCode
     * @param array<int>|null $accountIds
     * @return array
     */
    public function calculateRevaluation(
        string $asOfDate,
        array $closingRates,
        ?string $sbuCode = null,
        ?array $accountIds = null
    ): array {
        $functionalCurrency = config('accounting.currency', 'LKR');
        $tolerance = (float) config('accounting.rounding_tolerance', 0.005);
        $prefix = config('accounting.table_prefix', 'fincore_');

        // Query active candidate accounts
        $accountsQuery = Account::where('is_active', true);
        if (!empty($accountIds)) {
            $accountsQuery->whereIn('id', $accountIds);
        }
        $accounts = $accountsQuery->get()->filter(fn(Account $acc) => $this->isMonetaryAccount($acc));

        $items = [];
        $totalGain = 0.0;
        $totalLoss = 0.0;

        foreach ($closingRates as $currency => $rate) {
            $currency = strtoupper(trim((string) $currency));
            $closingRate = (float) $rate;

            if ($currency === $functionalCurrency) {
                continue; // Cannot revalue functional currency against itself
            }

            if ($closingRate <= 0) {
                throw new AccountingException("Closing exchange rate for currency '{$currency}' must be greater than zero.");
            }

            foreach ($accounts as $account) {
                $linesQuery = DB::table($prefix . 'journal_entry_lines as lines')
                    ->join($prefix . 'journal_entries as entries', 'lines.journal_entry_id', '=', 'entries.id')
                    ->where('lines.account_id', $account->id)
                    ->where('entries.status', JvStatus::POSTED->value)
                    ->where('entries.date', '<=', $asOfDate);

                if ($sbuCode) {
                    $linesQuery->where('entries.sbu_code', $sbuCode);
                }

                // Filter lines for this foreign currency
                $linesQuery->where(function ($q) use ($currency, $account) {
                    $q->where('entries.currency', $currency)
                        ->orWhere('entries.reference', 'like', "REVAL-{$currency}-%")
                        ->orWhere('entries.reference', 'like', "REV-REVAL-{$currency}-%");

                    if ($account->currency === $currency) {
                        $q->orWhere('entries.currency', $currency);
                    }
                });

                $aggregated = $linesQuery->selectRaw("
                    SUM(CASE WHEN lines.type = 'debit' THEN COALESCE(lines.fc_amount, 0) ELSE 0 END) as fc_debit,
                    SUM(CASE WHEN lines.type = 'credit' THEN COALESCE(lines.fc_amount, 0) ELSE 0 END) as fc_credit,
                    SUM(CASE WHEN lines.type = 'debit' THEN lines.amount ELSE 0 END) as amount_debit,
                    SUM(CASE WHEN lines.type = 'credit' THEN lines.amount ELSE 0 END) as amount_credit
                ")->first();

                if (!$aggregated) {
                    continue;
                }

                if ($account->hasNormalDebitBalance()) {
                    $fcBalance = (float) ($aggregated->fc_debit - $aggregated->fc_credit);
                    $bookValue = (float) ($aggregated->amount_debit - $aggregated->amount_credit);
                } else {
                    $fcBalance = (float) ($aggregated->fc_credit - $aggregated->fc_debit);
                    $bookValue = (float) ($aggregated->amount_credit - $aggregated->amount_debit);
                }

                if (abs($fcBalance) < 0.0001) {
                    continue;
                }

                $revaluedAmount = round($fcBalance * $closingRate, 4);
                $diff = round($revaluedAmount - $bookValue, 4);

                $gain = 0.0;
                $loss = 0.0;

                if ($account->type === AccountType::ASSET) {
                    if ($diff > 0) {
                        $gain = $diff;
                    } elseif ($diff < 0) {
                        $loss = abs($diff);
                    }
                } elseif ($account->type === AccountType::LIABILITY) {
                    // For liabilities, an increase in obligation is a loss; decrease is a gain.
                    if ($diff > 0) {
                        $loss = $diff;
                    } elseif ($diff < 0) {
                        $gain = abs($diff);
                    }
                }

                if ($gain <= $tolerance && $loss <= $tolerance) {
                    continue;
                }

                $items[] = [
                    'account_id'      => $account->id,
                    'account_code'    => $account->code,
                    'account_name'    => $account->name,
                    'account_type'    => $account->type->value,
                    'currency'        => $currency,
                    'fc_balance'      => round($fcBalance, 4),
                    'book_value'      => round($bookValue, 4),
                    'closing_rate'    => $closingRate,
                    'revalued_amount' => $revaluedAmount,
                    'unrealized_gain' => $gain,
                    'unrealized_loss' => $loss,
                    'net_adjustment'  => round($gain - $loss, 4),
                ];

                $totalGain += $gain;
                $totalLoss += $loss;
            }
        }

        return [
            'as_of_date'    => $asOfDate,
            'closing_rates' => $closingRates,
            'items'         => $items,
            'total_gain'    => round($totalGain, 4),
            'total_loss'    => round($totalLoss, 4),
            'net_gain_loss' => round($totalGain - $totalLoss, 4),
        ];
    }

    /**
     * Perform unrealized forex revaluation and generate adjusting journal entries.
     * Optionally auto-reverses on the first day of the subsequent fiscal period.
     */
    public function revalue(
        string $asOfDate,
        array $closingRates,
        bool $autoReverse = true,
        ?string $reversalDate = null,
        ?string $sbuCode = null,
        ?array $accountIds = null,
        ?int $gainAccountId = null,
        ?int $lossAccountId = null,
        ?int $userId = null
    ): array {
        return DB::transaction(function () use (
            $asOfDate,
            $closingRates,
            $autoReverse,
            $reversalDate,
            $sbuCode,
            $accountIds,
            $gainAccountId,
            $lossAccountId,
            $userId
        ) {
            $calculation = $this->calculateRevaluation($asOfDate, $closingRates, $sbuCode, $accountIds);

            if (empty($calculation['items'])) {
                return array_merge($calculation, [
                    'journal_entries'  => [],
                    'reversal_entries' => [],
                    'journal_entry'    => null,
                    'reversal_entry'   => null,
                ]);
            }

            $gainAccount = $this->resolveGainAccount($gainAccountId);
            $lossAccount = $this->resolveLossAccount($lossAccountId);
            $ledgerEngine = new LedgerEngine();

            $createdRevalEntries = [];
            $createdReversalEntries = [];

            // Group items by currency
            $itemsByCurrency = collect($calculation['items'])->groupBy('currency');

            foreach ($itemsByCurrency as $currency => $currencyItems) {
                $dateTag = date('Ymd', strtotime($asOfDate));
                $ref = "REVAL-{$currency}-{$dateTag}";

                // Guard against duplicate revaluation entries for the same period and currency
                $existing = JournalEntry::where('reference', $ref)
                    ->where('status', '!=', JvStatus::VOID)
                    ->first();

                if ($existing) {
                    throw new AccountingException(
                        "Forex revaluation for {$currency} as of {$asOfDate} already exists ({$existing->entry_number}). Void or reverse it first."
                    );
                }

                $revalLines = [];
                $currencyTotalGain = 0.0;
                $currencyTotalLoss = 0.0;

                foreach ($currencyItems as $item) {
                    $gain = (float) $item['unrealized_gain'];
                    $loss = (float) $item['unrealized_loss'];

                    if ($item['account_type'] === AccountType::ASSET->value) {
                        if ($gain > 0) {
                            $revalLines[] = [
                                'account_id'  => $item['account_id'],
                                'type'        => 'debit',
                                'amount'      => $gain,
                                'description' => "Unrealized forex gain on {$item['account_name']} ({$currency} @ {$item['closing_rate']})",
                            ];
                            $currencyTotalGain += $gain;
                        } elseif ($loss > 0) {
                            $revalLines[] = [
                                'account_id'  => $item['account_id'],
                                'type'        => 'credit',
                                'amount'      => $loss,
                                'description' => "Unrealized forex loss on {$item['account_name']} ({$currency} @ {$item['closing_rate']})",
                            ];
                            $currencyTotalLoss += $loss;
                        }
                    } elseif ($item['account_type'] === AccountType::LIABILITY->value) {
                        if ($gain > 0) {
                            $revalLines[] = [
                                'account_id'  => $item['account_id'],
                                'type'        => 'debit',
                                'amount'      => $gain,
                                'description' => "Unrealized forex gain on {$item['account_name']} ({$currency} @ {$item['closing_rate']})",
                            ];
                            $currencyTotalGain += $gain;
                        } elseif ($loss > 0) {
                            $revalLines[] = [
                                'account_id'  => $item['account_id'],
                                'type'        => 'credit',
                                'amount'      => $loss,
                                'description' => "Unrealized forex loss on {$item['account_name']} ({$currency} @ {$item['closing_rate']})",
                            ];
                            $currencyTotalLoss += $loss;
                        }
                    }
                }

                if ($currencyTotalGain > 0) {
                    $revalLines[] = [
                        'account_id'  => $gainAccount->id,
                        'type'        => 'credit',
                        'amount'      => round($currencyTotalGain, 4),
                        'description' => "Unrealized foreign exchange gain ({$currency})",
                    ];
                }

                if ($currencyTotalLoss > 0) {
                    $revalLines[] = [
                        'account_id'  => $lossAccount->id,
                        'type'        => 'debit',
                        'amount'      => round($currencyTotalLoss, 4),
                        'description' => "Unrealized foreign exchange loss ({$currency})",
                    ];
                }

                if (empty($revalLines)) {
                    continue;
                }

                $revalEntry = $ledgerEngine->createJournalEntry([
                    'date'        => $asOfDate,
                    'reference'   => $ref,
                    'type'        => JvType::ADJUSTMENT->value,
                    'description' => "Unrealized foreign exchange revaluation for {$currency} as of {$asOfDate}",
                    'lines'       => $revalLines,
                    'sbu_code'    => $sbuCode,
                    'created_by'  => $userId,
                ]);

                $revalEntry->post(bypassPeriodLock: true, userId: $userId);
                $createdRevalEntries[] = $revalEntry;

                if ($autoReverse) {
                    $targetReversalDate = $reversalDate ?: $this->determineNextPeriodStartDate($asOfDate);
                    $reversalRef = "REV-" . $ref;

                    $reversalLines = [];
                    foreach ($revalLines as $line) {
                        $reversalLines[] = [
                            'account_id'  => $line['account_id'],
                            'type'        => ($line['type'] === 'debit') ? 'credit' : 'debit',
                            'amount'      => $line['amount'],
                            'description' => "Auto-reversal: " . $line['description'],
                        ];
                    }

                    $reversalEntry = $ledgerEngine->createJournalEntry([
                        'date'        => $targetReversalDate,
                        'reference'   => $reversalRef,
                        'type'        => JvType::ADJUSTMENT->value,
                        'description' => "Auto-reversal of unrealized forex revaluation [{$revalEntry->entry_number}] for {$currency} as of {$asOfDate}",
                        'lines'       => $reversalLines,
                        'sbu_code'    => $sbuCode,
                        'created_by'  => $userId,
                    ]);

                    $reversalEntry->post(bypassPeriodLock: true, userId: $userId);
                    $createdReversalEntries[] = $reversalEntry;
                }
            }

            return array_merge($calculation, [
                'journal_entries'  => $createdRevalEntries,
                'reversal_entries' => $createdReversalEntries,
                'journal_entry'    => $createdRevalEntries[0] ?? null,
                'reversal_entry'   => $createdReversalEntries[0] ?? null,
            ]);
        });
    }

    /**
     * Reverse an existing posted forex revaluation journal entry.
     */
    public function reverseRevaluationEntry(
        JournalEntry|int $entry,
        ?string $reversalDate = null,
        ?int $userId = null
    ): JournalEntry {
        return DB::transaction(function () use ($entry, $reversalDate, $userId) {
            if (is_numeric($entry)) {
                $entry = JournalEntry::findOrFail($entry);
            }

            if ($entry->status !== JvStatus::POSTED) {
                throw new AccountingException("Only posted revaluation entries can be reversed.");
            }

            $reversalRef = "REV-" . $entry->reference;
            $existing = JournalEntry::where('reference', $reversalRef)
                ->where('status', '!=', JvStatus::VOID)
                ->first();

            if ($existing) {
                throw new AccountingException(
                    "Revaluation entry '{$entry->entry_number}' has already been reversed by '{$existing->entry_number}'."
                );
            }

            $entryDate = is_string($entry->date) ? $entry->date : $entry->date->format('Y-m-d');
            $targetReversalDate = $reversalDate ?: $this->determineNextPeriodStartDate($entryDate);

            $reversalLines = [];
            foreach ($entry->lines as $line) {
                $reversalLines[] = [
                    'account_id'  => $line->account_id,
                    'type'        => ($line->type === 'debit') ? 'credit' : 'debit',
                    'amount'      => (float) $line->amount,
                    'description' => "Auto-reversal: " . ($line->description ?: "Revaluation line"),
                ];
            }

            $ledgerEngine = new LedgerEngine();
            $reversalEntry = $ledgerEngine->createJournalEntry([
                'date'        => $targetReversalDate,
                'reference'   => $reversalRef,
                'type'        => JvType::ADJUSTMENT->value,
                'description' => "Auto-reversal of unrealized forex revaluation [{$entry->entry_number}] as of {$entryDate}",
                'lines'       => $reversalLines,
                'sbu_code'    => $entry->sbu_code,
                'created_by'  => $userId,
            ]);

            $reversalEntry->post(bypassPeriodLock: true, userId: $userId);

            return $reversalEntry;
        });
    }

    /**
     * Determine whether an account is classified as a monetary account eligible for forex revaluation.
     * Excludes non-monetary items (inventory, fixed assets, prepayments, equity, nominal accounts).
     */
    public function isMonetaryAccount(Account $account): bool
    {
        // 1. Only Balance Sheet Asset & Liability accounts can be monetary
        if (!in_array($account->type, [AccountType::ASSET, AccountType::LIABILITY])) {
            return false;
        }

        // 2. Explicitly configured non-monetary account codes (inventory, prepayments, fixed assets, equity)
        $nonMonetaryCodes = config('accounting.non_monetary_account_codes', [
            '1300', '1400', '1500', '1550', '2100', '3000', '3099', '3100', '3900'
        ]);
        if (in_array((string) $account->code, $nonMonetaryCodes, true)) {
            return false;
        }

        // 3. Exclude non-monetary subtypes
        if (in_array($account->subtype, ['fixed_asset', 'non_current_asset', 'inventory', 'prepaid', 'equity'])) {
            return false;
        }

        // 4. Configured monetary account codes (Cash/Bank, AR, AP, Loans)
        $monetaryCodes = config('accounting.monetary_account_codes', [
            '1000', '1100', '1200', '2000', '2400', '2500'
        ]);
        if (in_array((string) $account->code, $monetaryCodes, true)) {
            return true;
        }

        // 5. Standard cash, AR, AP, and financing codes
        $cashCodes = config('accounting.cash_account_codes', ['1000', '1100']);
        $arCodes = config('accounting.ar_account_codes', ['1200']);
        $apCodes = config('accounting.ap_account_codes', ['2000']);
        $financingCodes = config('accounting.financing_account_codes', ['2400', '2500']);

        if (in_array((string) $account->code, array_merge($cashCodes, $arCodes, $apCodes, $financingCodes), true)) {
            return true;
        }

        // 6. Common monetary subtypes
        if (in_array($account->subtype, ['current_asset', 'current_liability', 'receivable', 'payable', 'bank', 'cash'])) {
            return true;
        }

        // 7. Any account denominated in a foreign currency
        $functionalCurrency = config('accounting.currency', 'LKR');
        if (!empty($account->currency) && $account->currency !== $functionalCurrency) {
            return true;
        }

        return false;
    }

    /**
     * Determine the start date of the subsequent fiscal period, or fallback to the next day.
     */
    public function determineNextPeriodStartDate(string $asOfDate): string
    {
        $currentPeriod = FiscalPeriod::where('start_date', '<=', $asOfDate)
            ->where('end_date', '>=', $asOfDate)
            ->first();

        if ($currentPeriod) {
            $nextPeriod = FiscalPeriod::where('fiscal_year_id', $currentPeriod->fiscal_year_id)
                ->where('start_date', '>', $currentPeriod->end_date)
                ->orderBy('start_date')
                ->first();

            if ($nextPeriod) {
                return is_string($nextPeriod->start_date)
                    ? $nextPeriod->start_date
                    : $nextPeriod->start_date->format('Y-m-d');
            }
        }

        return date('Y-m-d', strtotime($asOfDate . ' +1 day'));
    }

    /**
     * Resolve or automatically create the Unrealized Forex Gain account.
     */
    protected function resolveGainAccount(?int $accountId = null): Account
    {
        if ($accountId) {
            return Account::findOrFail($accountId);
        }

        $code = (string) config('accounting.forex_gain_account_code', '4900');
        $account = Account::where('code', $code)->first();

        if (!$account) {
            $account = Account::create([
                'code'      => $code,
                'name'      => 'Unrealized Foreign Exchange Gain',
                'type'      => AccountType::REVENUE,
                'subtype'   => 'other_income',
                'currency'  => config('accounting.currency', 'LKR'),
                'is_active' => true,
            ]);
        }

        return $account;
    }

    /**
     * Resolve or automatically create the Unrealized Forex Loss account.
     */
    protected function resolveLossAccount(?int $accountId = null): Account
    {
        if ($accountId) {
            return Account::findOrFail($accountId);
        }

        $code = (string) config('accounting.forex_loss_account_code', '6900');
        $account = Account::where('code', $code)->first();

        if (!$account) {
            $account = Account::create([
                'code'      => $code,
                'name'      => 'Unrealized Foreign Exchange Loss',
                'type'      => AccountType::EXPENSE,
                'subtype'   => 'other_expense',
                'currency'  => config('accounting.currency', 'LKR'),
                'is_active' => true,
            ]);
        }

        return $account;
    }
}
