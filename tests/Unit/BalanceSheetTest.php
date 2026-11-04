<?php

namespace Nml\FinCore\Tests\Unit;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Tests\TestCase;

class BalanceSheetTest extends TestCase
{
    public function test_balance_sheet_rolls_up_current_year_earnings_when_unclosed(): void
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

        $expense = Account::create([
            'code'      => '6200',
            'name'      => 'Rent Expense',
            'type'      => AccountType::EXPENSE,
            'subtype'   => 'expense',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        // Transaction 1: Revenue 10,000 (Debit Cash 10,000, Credit Revenue 10,000)
        $entry1 = FinCore::createJournalEntry([
            'date'  => '2026-06-10',
            'lines' => [
                ['account_id' => $cash->id, 'type' => 'debit', 'amount' => 10000.00],
                ['account_id' => $revenue->id, 'type' => 'credit', 'amount' => 10000.00],
            ],
        ]);
        $entry1->post();

        // Transaction 2: Expense 3,000 (Debit Expense 3,000, Credit Cash 3,000)
        $entry2 = FinCore::createJournalEntry([
            'date'  => '2026-06-15',
            'lines' => [
                ['account_id' => $expense->id, 'type' => 'debit', 'amount' => 3000.00],
                ['account_id' => $cash->id, 'type' => 'credit', 'amount' => 3000.00],
            ],
        ]);
        $entry2->post();

        // Net income = 10,000 - 3,000 = 7,000
        // Cash = 7,000. Assets = 7,000. Liabilities = 0. Equity = 7,000 (current year earnings)
        $bs = FinCore::getBalanceSheet('2026-06-30');

        $this->assertTrue($bs['is_balanced'], 'Balance sheet should balance with dynamic current year earnings');
        $this->assertEquals(7000.00, $bs['assets']['total']);
        $this->assertEquals(0.00, $bs['liabilities']['total']);
        $this->assertEquals(7000.00, $bs['equity']['total']);
        $this->assertEquals(7000.00, $bs['current_year_earnings']);
    }
}
