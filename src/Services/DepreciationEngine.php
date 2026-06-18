<?php

namespace Nml\FinCore\Services;

use Illuminate\Support\Facades\DB;
use Nml\FinCore\Enums\JvType;
use Nml\FinCore\Exceptions\AccountingException;
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Models\FixedAsset;
use Nml\FinCore\Models\DepreciationLog;
use Nml\FinCore\Models\JournalEntry;

class DepreciationEngine
{
    /**
     * Calculate depreciation amount for a specific asset for one month.
     */
    public function calculateMonthlyDepreciation(FixedAsset $asset, string $date): float
    {
        $year = date('Y', strtotime($date));
        $month = date('m', strtotime($date));

        // Get accumulated depreciation logged so far
        $accumulated = (float) DepreciationLog::where('fixed_asset_id', $asset->id)->sum('amount');
        $depreciableLimit = $asset->purchase_cost - $asset->salvage_value;
        $remainingDepreciable = $depreciableLimit - $accumulated;

        if ($remainingDepreciable <= 0.005) {
            return 0.0;
        }

        $monthlyAmount = 0.0;

        if ($asset->depreciation_method === 'straight_line') {
            $monthlyAmount = ($depreciableLimit * ($asset->depreciation_rate / 100)) / 12;
        } elseif ($asset->depreciation_method === 'reducing_balance') {
            $bookValue = $asset->purchase_cost - $accumulated;
            $monthlyAmount = ($bookValue * ($asset->depreciation_rate / 100)) / 12;
        }

        if ($monthlyAmount > $remainingDepreciable) {
            $monthlyAmount = $remainingDepreciable;
        }

        return round($monthlyAmount, 2);
    }

    /**
     * Calculate and post depreciation for a single asset.
     */
    public function postDepreciationForAsset(int $assetId, string $date): ?JournalEntry
    {
        return DB::transaction(function () use ($assetId, $date) {
            $asset = FixedAsset::findOrFail($assetId);
            $year = date('Y', strtotime($date));
            $month = date('m', strtotime($date));

            // Check if already posted in this calendar month
            $exists = DepreciationLog::where('fixed_asset_id', $asset->id)
                ->whereYear('depreciation_date', $year)
                ->whereMonth('depreciation_date', $month)
                ->exists();

            if ($exists) {
                throw new AccountingException("Depreciation already posted for asset {$asset->code} in {$year}-{$month}.");
            }

            $amount = $this->calculateMonthlyDepreciation($asset, $date);

            if ($amount <= 0.01) {
                return null;
            }

            // Create double-entry journal entry
            $entry = FinCore::createJournalEntry([
                'date' => $date,
                'reference' => 'DEP-' . $asset->code . '-' . $year . $month,
                'type' => JvType::ADJUSTMENT->value,
                'description' => "Monthly depreciation for Fixed Asset: {$asset->name} ({$asset->code})",
                'lines' => [
                    [
                        'account_id' => $asset->depreciation_expense_account_id,
                        'type' => 'debit',
                        'amount' => $amount,
                        'description' => "Depreciation expense - " . $asset->code,
                    ],
                    [
                        'account_id' => $asset->accumulated_depreciation_account_id,
                        'type' => 'credit',
                        'amount' => $amount,
                        'description' => "Accumulated depreciation - " . $asset->code,
                    ]
                ]
            ]);

            $entry->post();

            // Log depreciation run
            DepreciationLog::create([
                'fixed_asset_id' => $asset->id,
                'journal_entry_id' => $entry->id,
                'depreciation_date' => $date,
                'amount' => $amount,
            ]);

            return $entry;
        });
    }

    /**
     * Post depreciation for all active fixed assets.
     */
    public function postDepreciationForAllActiveAssets(string $date): array
    {
        $assets = FixedAsset::where('status', 'active')->get();
        $postedEntries = [];

        foreach ($assets as $asset) {
            try {
                $entry = $this->postDepreciationForAsset($asset->id, $date);
                if ($entry) {
                    $postedEntries[$asset->code] = [
                        'status' => 'success',
                        'amount' => $entry->lines()->where('type', 'debit')->first()->amount,
                        'journal_entry_id' => $entry->id,
                    ];
                } else {
                    $postedEntries[$asset->code] = [
                        'status' => 'skipped',
                        'reason' => 'Asset fully depreciated',
                    ];
                }
            } catch (\Exception $e) {
                $postedEntries[$asset->code] = [
                    'status' => 'failed',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $postedEntries;
    }
}
