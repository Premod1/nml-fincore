<?php

namespace Nml\FinCore\Services;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\JournalEntryLine;

class PartnerAgeingEngine
{
    /**
     * Generate an ageing report for Accounts Receivable.
     */
    public function getReceivablesAgeingReport(string $asOfDate, ?array $accountIds = null): array
    {
        return $this->generateAgeingReport($asOfDate, 'receivable', $accountIds);
    }

    /**
     * Generate an ageing report for Accounts Payable.
     */
    public function getPayablesAgeingReport(string $asOfDate, ?array $accountIds = null): array
    {
        return $this->generateAgeingReport($asOfDate, 'payable', $accountIds);
    }

    /**
     * Core FIFO Ageing calculation engine.
     */
    private function generateAgeingReport(string $asOfDate, string $type, ?array $accountIds = null): array
    {
        if (!empty($accountIds)) {
            $accounts = Account::whereIn('id', $accountIds)->where('is_active', true)->get();
        } elseif ($type === 'receivable') {
            $arCodes = config('accounting.ar_account_codes', ['1200']);
            $accounts = Account::where('is_active', true)
                ->where('type', AccountType::ASSET)
                ->where(function ($query) use ($arCodes) {
                    $query->whereIn('code', $arCodes)
                        ->orWhere('subtype', 'receivable');
                })
                ->get();
        } else {
            $apCodes = config('accounting.ap_account_codes', ['2000']);
            $accounts = Account::where('is_active', true)
                ->where('type', AccountType::LIABILITY)
                ->where(function ($query) use ($apCodes) {
                    $query->whereIn('code', $apCodes)
                        ->orWhere('subtype', 'payable');
                })
                ->get();
        }

        if ($accounts->isEmpty()) {
            return [];
        }

        $targetAccountIds = $accounts->pluck('id')->toArray();
        $prefix = config('accounting.table_prefix', 'fincore_');

        // Fetch lines using Query Builder (stdClass) with direct JOIN to avoid Eloquent hydration OOM (Issue #10)
        $lines = \Illuminate\Support\Facades\DB::table($prefix . 'journal_entry_lines as lines')
            ->join($prefix . 'journal_entries as entries', 'lines.journal_entry_id', '=', 'entries.id')
            ->whereIn('lines.account_id', $targetAccountIds)
            ->where('entries.status', JvStatus::POSTED->value)
            ->where('entries.date', '<=', $asOfDate)
            ->select(
                'lines.id',
                'lines.type',
                'lines.amount',
                'lines.due_date',
                'lines.partnerable_type',
                'lines.partnerable_id',
                'entries.date as entry_date'
            )
            ->get();

        // Group lines by partner and collect unique partner IDs for batch loading
        $partnerGroups = [];
        $partnersByType = [];

        foreach ($lines as $line) {
            $key = $line->partnerable_type && $line->partnerable_id
                ? $line->partnerable_type . ':' . $line->partnerable_id
                : 'Unassigned';

            $partnerGroups[$key][] = $line;

            if ($line->partnerable_type && $line->partnerable_id) {
                $partnersByType[$line->partnerable_type][] = $line->partnerable_id;
            }
        }

        // Efficient batch resolution of partner names (avoids loading models per line)
        $partnerNames = [];
        foreach ($partnersByType as $modelClass => $ids) {
            if (class_exists($modelClass)) {
                $uniqueIds = array_unique($ids);
                try {
                    $models = $modelClass::whereIn('id', $uniqueIds)->get();
                    foreach ($models as $m) {
                        $partnerNames[$modelClass . ':' . $m->id] = $m->name ?? ($m->title ?? ($modelClass . ' #' . $m->id));
                    }
                } catch (\Throwable $e) {
                    // Fallback to identifier if query fails
                }
            }
        }

        $report = [];

        foreach ($partnerGroups as $key => $partnerLines) {
            $partnerName = 'Unassigned';
            $partnerType = null;
            $partnerId = null;

            if ($key !== 'Unassigned') {
                $firstLine = $partnerLines[0];
                $partnerType = $firstLine->partnerable_type;
                $partnerId = $firstLine->partnerable_id;
                $partnerName = $partnerNames[$key] ?? $key;
            }

            // Split into positive charges and credit/payment adjustments
            $charges = [];
            $paymentsTotal = 0.0;

            foreach ($partnerLines as $line) {
                $amount = (float) $line->amount;

                if ($type === 'receivable') {
                    // Receivables: Debits are charges, Credits are payments
                    if ($line->type === 'debit') {
                        $charges[] = $line;
                    } else {
                        $paymentsTotal += $amount;
                    }
                } else {
                    // Payables: Credits are charges (bills), Debits are payments
                    if ($line->type === 'credit') {
                        $charges[] = $line;
                    } else {
                        $paymentsTotal += $amount;
                    }
                }
            }

            // Sort charges by date (FIFO - oldest first)
            usort($charges, function ($a, $b) {
                $dateA = $a->due_date ?? $a->entry_date;
                $dateB = $b->due_date ?? $b->entry_date;
                $strA = $dateA instanceof \DateTimeInterface ? $dateA->format('Y-m-d') : (string) $dateA;
                $strB = $dateB instanceof \DateTimeInterface ? $dateB->format('Y-m-d') : (string) $dateB;
                return strcmp($strA, $strB);
            });

            // Allocate payments against charges using FIFO
            $buckets = [
                'current' => 0.0,
                '1_30'    => 0.0,
                '31_60'   => 0.0,
                '61_90'   => 0.0,
                '91_plus' => 0.0,
            ];

            $totalOutstanding = 0.0;

            foreach ($charges as $charge) {
                $chargeAmount = (float) $charge->amount;

                if ($paymentsTotal > 0.0) {
                    if ($paymentsTotal >= $chargeAmount) {
                        $paymentsTotal -= $chargeAmount;
                        $chargeAmount = 0.0;
                    } else {
                        $chargeAmount -= $paymentsTotal;
                        $paymentsTotal = 0.0;
                    }
                }

                if ($chargeAmount > 0.005) {
                    // Calculate Age of this outstanding amount
                    $chargeDate = $charge->due_date ?? $charge->entry_date;
                    $chargeDateStr = $chargeDate instanceof \DateTimeInterface ? $chargeDate->format('Y-m-d') : (string) $chargeDate;
                    
                    $asOfTimestamp = strtotime($asOfDate);
                    $chargeTimestamp = strtotime($chargeDateStr);
                    
                    $ageDays = ($asOfTimestamp - $chargeTimestamp) / 86400;

                    if ($ageDays <= 0) {
                        $buckets['current'] += $chargeAmount;
                    } elseif ($ageDays <= 30) {
                        $buckets['1_30'] += $chargeAmount;
                    } elseif ($ageDays <= 60) {
                        $buckets['31_60'] += $chargeAmount;
                    } elseif ($ageDays <= 90) {
                        $buckets['61_90'] += $chargeAmount;
                    } else {
                        $buckets['91_plus'] += $chargeAmount;
                    }

                    $totalOutstanding += $chargeAmount;
                }
            }

            $report[] = [
                'partner_type'      => $partnerType,
                'partner_id'        => $partnerId,
                'partner_name'      => $partnerName,
                'total_outstanding' => round($totalOutstanding, 2),
                'current'           => round($buckets['current'], 2),
                '1_30'              => round($buckets['1_30'], 2),
                '31_60'             => round($buckets['31_60'], 2),
                '61_90'             => round($buckets['61_90'], 2),
                '91_plus'           => round($buckets['91_plus'], 2),
            ];
        }

        return $report;
    }
}
