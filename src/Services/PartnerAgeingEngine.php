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

        // Fetch all lines posted up to the target date
        $lines = JournalEntryLine::whereIn('account_id', $targetAccountIds)
            ->whereHas('journalEntry', function ($query) use ($asOfDate) {
                $query->where('status', JvStatus::POSTED)
                    ->where('date', '<=', $asOfDate);
            })
            ->with(['journalEntry', 'partnerable'])
            ->get();

        // Group lines by partner
        $partnerGroups = [];
        foreach ($lines as $line) {
            $key = $line->partnerable_type && $line->partnerable_id
                ? $line->partnerable_type . ':' . $line->partnerable_id
                : 'Unassigned';

            $partnerGroups[$key][] = $line;
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
                
                // Get partner name if model has a 'name' property
                if ($firstLine->partnerable) {
                    $partnerName = $firstLine->partnerable->name ?? ($firstLine->partnerable->title ?? $key);
                } else {
                    $partnerName = $key;
                }
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
                $dateA = $a->due_date ?? $a->journalEntry->date;
                $dateB = $b->due_date ?? $b->journalEntry->date;
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
                    $chargeDate = $charge->due_date ?? $charge->journalEntry->date;
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
