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
    public function getReceivablesAgeingReport(string $asOfDate): array
    {
        return $this->generateAgeingReport($asOfDate, AccountType::RECEIVABLE->value);
    }

    /**
     * Generate an ageing report for Accounts Payable.
     */
    public function getPayablesAgeingReport(string $asOfDate): array
    {
        return $this->generateAgeingReport($asOfDate, AccountType::PAYABLE->value);
    }

    /**
     * Core FIFO Ageing calculation engine.
     */
    private function generateAgeingReport(string $asOfDate, string $accountTypeValue): array
    {
        $accounts = Account::where('type', $accountTypeValue)->get();
        if ($accounts->isEmpty()) {
            return [];
        }

        $accountIds = $accounts->pluck('id')->toArray();

        // Fetch all lines posted up to the target date
        $lines = JournalEntryLine::whereIn('account_id', $accountIds)
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

                if ($accountTypeValue === AccountType::RECEIVABLE->value) {
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
                return strcmp($dateA->format('Y-m-d'), $dateB->format('Y-m-d'));
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
                    $asOfTimestamp = strtotime($asOfDate);
                    $chargeTimestamp = strtotime($chargeDate->format('Y-m-d'));
                    
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
