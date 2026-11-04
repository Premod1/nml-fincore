<?php

namespace Nml\FinCore\Tests\Unit;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Tests\TestCase;

class CashFlowTest extends TestCase
{
    public function test_cash_flow_statement_properly_handles_depreciation_and_contra_assets(): void
    {
        $cash = Account::create([
            'code'      => '1000',
            'name'      => 'Cash',
            'type'      => AccountType::ASSET,
            'subtype'   => 'current_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $revenue = Account::create([
            'code'      => '4000',
            'name'      => 'Sales Revenue',
            'type'      => AccountType::REVENUE,
            'subtype'   => 'revenue',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $deprExpense = Account::create([
            'code'      => '6500',
            'name'      => 'Depreciation Expense',
            'type'      => AccountType::EXPENSE,
            'subtype'   => 'expense',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $accumDepr = Account::create([
            'code'      => '1550',
            'name'      => 'Accumulated Depreciation',
            'type'      => AccountType::ASSET,
            'subtype'   => 'fixed_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        // 1. Cash sales: 50,000 (Debit Cash 50,000, Credit Revenue 50,000)
        $entry1 = FinCore::createJournalEntry([
            'date'  => '2026-06-05',
            'lines' => [
                ['account_id' => $cash->id, 'type' => 'debit', 'amount' => 50000.00],
                ['account_id' => $revenue->id, 'type' => 'credit', 'amount' => 50000.00],
            ],
        ]);
        $entry1->post();

        // 2. Non-cash depreciation: 5,000 (Debit Depr Expense 5,000, Credit Accum Depr 5,000)
        $entry2 = FinCore::createJournalEntry([
            'date'  => '2026-06-30',
            'lines' => [
                ['account_id' => $deprExpense->id, 'type' => 'debit', 'amount' => 5000.00],
                ['account_id' => $accumDepr->id, 'type' => 'credit', 'amount' => 5000.00],
            ],
        ]);
        $entry2->post();

        // Net income = 50,000 - 5,000 = 45,000
        // Operating cash flow = 45,000 + 5,000 (depreciation add-back) = 50,000
        // Investing cash flow = 0 (Accumulated depreciation excluded from investing)
        // Ending cash = 50,000
        $cf = FinCore::getCashFlowStatement('2026-06-01', '2026-06-30');

        $this->assertEquals(45000.00, $cf['net_income']);
        $this->assertEquals(50000.00, $cf['operating_activities']['total']);
        $this->assertEquals(0.00, $cf['investing_activities']['total']);
        $this->assertEquals(50000.00, $cf['ending_cash']);
        $this->assertTrue($cf['is_reconciled']);
    }
}
