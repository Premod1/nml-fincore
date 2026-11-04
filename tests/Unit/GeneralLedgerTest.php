<?php

namespace Nml\FinCore\Tests\Unit;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Tests\TestCase;

class GeneralLedgerTest extends TestCase
{
    public function test_pagination_running_balance_is_consistent_across_pages(): void
    {
        $bank = Account::create([
            'code'      => '1100',
            'name'      => 'Bank',
            'type'      => AccountType::ASSET,
            'subtype'   => 'current_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $revenue = Account::create([
            'code'      => '4000',
            'name'      => 'Revenue',
            'type'      => AccountType::REVENUE,
            'subtype'   => 'revenue',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        // Post 5 transactions of 100 each on consecutive days
        for ($i = 1; $i <= 5; $i++) {
            $day = str_pad($i, 2, '0', STR_PAD_LEFT);
            $entry = FinCore::createJournalEntry([
                'date'  => "2026-06-{$day}",
                'lines' => [
                    ['account_id' => $bank->id, 'type' => 'debit', 'amount' => 100.00],
                    ['account_id' => $revenue->id, 'type' => 'credit', 'amount' => 100.00],
                ],
            ]);
            $entry->post();
        }

        // Page 1 (2 per page): items 1 and 2
        $page1 = FinCore::getGeneralLedger(
            accountId: $bank->id,
            startDate: '2026-06-01',
            endDate: '2026-06-30',
            perPage: 2,
            page: 1
        );

        $entries1 = $page1['accounts'][0]['entries'];
        $this->assertCount(2, $entries1);
        $this->assertEquals(100.00, $entries1[0]['running_balance']);
        $this->assertEquals(200.00, $entries1[1]['running_balance']);

        // Page 2 (2 per page): items 3 and 4 -> should start at 200, so balances are 300 and 400
        $page2 = FinCore::getGeneralLedger(
            accountId: $bank->id,
            startDate: '2026-06-01',
            endDate: '2026-06-30',
            perPage: 2,
            page: 2
        );

        $entries2 = $page2['accounts'][0]['entries'];
        $this->assertCount(2, $entries2);
        $this->assertEquals(300.00, $entries2[0]['running_balance'], 'Page 2 first entry running balance should account for page 1');
        $this->assertEquals(400.00, $entries2[1]['running_balance']);

        // Page 3 (2 per page): item 5 -> should start at 400, balance 500
        $page3 = FinCore::getGeneralLedger(
            accountId: $bank->id,
            startDate: '2026-06-01',
            endDate: '2026-06-30',
            perPage: 2,
            page: 3
        );

        $entries3 = $page3['accounts'][0]['entries'];
        $this->assertCount(1, $entries3);
        $this->assertEquals(500.00, $entries3[0]['running_balance']);
    }
}
