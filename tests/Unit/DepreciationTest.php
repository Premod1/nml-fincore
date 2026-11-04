<?php

namespace Nml\FinCore\Tests\Unit;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\DepreciationLog;
use Nml\FinCore\Models\FixedAsset;
use Nml\FinCore\Tests\TestCase;

class DepreciationTest extends TestCase
{
    public function test_voiding_depreciation_entry_cleans_up_depreciation_log(): void
    {
        $assetAccount = Account::create([
            'code'      => '1500',
            'name'      => 'Vehicles',
            'type'      => AccountType::ASSET,
            'subtype'   => 'fixed_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $accumAccount = Account::create([
            'code'      => '1550',
            'name'      => 'Accumulated Depreciation - Vehicles',
            'type'      => AccountType::ASSET,
            'subtype'   => 'fixed_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $expenseAccount = Account::create([
            'code'      => '6500',
            'name'      => 'Depreciation Expense',
            'type'      => AccountType::EXPENSE,
            'subtype'   => 'expense',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $asset = FixedAsset::create([
            'name'                               => 'Delivery Van',
            'code'                               => 'VAN-001',
            'purchase_date'                      => '2026-01-01',
            'purchase_cost'                      => 120000.00,
            'salvage_value'                      => 0.00,
            'useful_life_years'                  => 5,
            'depreciation_method'                => 'straight_line',
            'depreciation_rate'                  => 20.00,
            'asset_account_id'                   => $assetAccount->id,
            'accumulated_depreciation_account_id'=> $accumAccount->id,
            'depreciation_expense_account_id'    => $expenseAccount->id,
            'status'                             => 'active',
        ]);

        // Post depreciation for June 2026: (120,000 * 20% / 12) = 2,000
        $entry = FinCore::postDepreciationForAsset($asset->id, '2026-06-30');
        $this->assertNotNull($entry);
        $this->assertEquals(JvStatus::POSTED, $entry->status);

        // Verify depreciation log exists
        $this->assertTrue(
            DepreciationLog::where('fixed_asset_id', $asset->id)
                ->whereYear('depreciation_date', 2026)
                ->whereMonth('depreciation_date', 6)
                ->exists()
        );

        // Void the depreciation entry
        $entry->void();
        $this->assertEquals(JvStatus::VOID, $entry->fresh()->status);

        // Depreciation log must be purged so that accumulated depreciation and re-run are unblocked (Issue #6)
        $this->assertFalse(
            DepreciationLog::where('journal_entry_id', $entry->id)->exists(),
            'DepreciationLog must be deleted when the depreciation entry is voided'
        );

        // Can now re-run depreciation for that month without error
        $reEntry = FinCore::postDepreciationForAsset($asset->id, '2026-06-30');
        $this->assertNotNull($reEntry);
        $this->assertEquals(JvStatus::POSTED, $reEntry->status);
    }
}
