<?php

namespace Nml\FinCore\Tests\Unit;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Exceptions\AccountingException;
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\FiscalPeriod;
use Nml\FinCore\Models\FiscalYear;
use Nml\FinCore\Models\JournalEntry;
use Nml\FinCore\Tests\TestCase;

class ForexRevaluationTest extends TestCase
{
    private Account $usdBank;
    private Account $equity;
    private Account $arAccount;
    private Account $apAccount;
    private Account $salesRevenue;
    private Account $cogsExpense;
    private Account $inventory;
    private Account $fixedAsset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usdBank = Account::create([
            'code'      => '1101',
            'name'      => 'USD Bank Account',
            'type'      => AccountType::ASSET,
            'subtype'   => 'current_asset',
            'currency'  => 'USD',
            'is_active' => true,
        ]);

        $this->equity = Account::create([
            'code'      => '3000',
            'name'      => 'Share Capital',
            'type'      => AccountType::EQUITY,
            'subtype'   => 'equity',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->arAccount = Account::create([
            'code'      => '1200',
            'name'      => 'Accounts Receivable',
            'type'      => AccountType::ASSET,
            'subtype'   => 'receivable',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->apAccount = Account::create([
            'code'      => '2000',
            'name'      => 'Accounts Payable',
            'type'      => AccountType::LIABILITY,
            'subtype'   => 'payable',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->salesRevenue = Account::create([
            'code'      => '4000',
            'name'      => 'Sales Revenue',
            'type'      => AccountType::REVENUE,
            'subtype'   => 'revenue',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->cogsExpense = Account::create([
            'code'      => '5000',
            'name'      => 'Cost of Goods Sold',
            'type'      => AccountType::EXPENSE,
            'subtype'   => 'expense',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->inventory = Account::create([
            'code'      => '1300',
            'name'      => 'Merchandise Inventory',
            'type'      => AccountType::ASSET,
            'subtype'   => 'current_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->fixedAsset = Account::create([
            'code'      => '1500',
            'name'      => 'Machinery & Equipment',
            'type'      => AccountType::ASSET,
            'subtype'   => 'fixed_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);
    }

    public function test_can_revalue_foreign_bank_account_gain(): void
    {
        // 1. Initial deposit of 1,000 USD @ 300.00 LKR = 300,000 LKR on 2026-06-01
        $deposit = FinCore::createJournalEntry([
            'date'          => '2026-06-01',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'description'   => 'Capital infusion in USD',
            'lines'         => [
                [
                    'account_id' => $this->usdBank->id,
                    'type'       => 'debit',
                    'fc_amount'  => 1000.0,
                    'amount'     => 300000.0,
                ],
                [
                    'account_id' => $this->equity->id,
                    'type'       => 'credit',
                    'fc_amount'  => 1000.0,
                    'amount'     => 300000.0,
                ],
            ],
        ]);
        $deposit->post();

        $this->assertEquals(300000.0, $this->usdBank->getCurrentBalance());

        // 2. Revalue on 2026-06-30 at closing rate 320.00 LKR (+20 LKR per USD -> +20,000 LKR Gain)
        $result = FinCore::revalueForexBalances(
            asOfDate: '2026-06-30',
            closingRates: ['USD' => 320.0],
            autoReverse: true
        );

        $this->assertEquals(20000.0, $result['total_gain']);
        $this->assertEquals(0.0, $result['total_loss']);
        $this->assertEquals(20000.0, $result['net_gain_loss']);
        $this->assertNotNull($result['journal_entry']);
        $this->assertNotNull($result['reversal_entry']);

        // Check revaluation journal entry
        $revalEntry = $result['journal_entry'];
        $this->assertEquals(JvStatus::POSTED, $revalEntry->status);
        $this->assertEquals('2026-06-30', $revalEntry->date->format('Y-m-d'));
        $this->assertStringStartsWith('REVAL-USD-', $revalEntry->reference);

        // Functional balance at 2026-06-30 is now 320,000 LKR
        // Functional balance at 2026-06-30 is now 320,000 LKR
        $this->assertEquals(320000.0, $this->usdBank->getCurrentBalance(endDate: '2026-06-30'));

        // Check auto-reversal entry on 2026-07-01
        $reversalEntry = $result['reversal_entry'];
        $this->assertEquals(JvStatus::POSTED, $reversalEntry->status);
        $this->assertEquals('2026-07-01', $reversalEntry->date->format('Y-m-d'));
        $this->assertEquals('REV-' . $revalEntry->reference, $reversalEntry->reference);

        // After reversal on 2026-07-01, functional balance is returned to original 300,000 LKR
        $this->assertEquals(300000.0, $this->usdBank->getCurrentBalance(endDate: '2026-07-01'));
    }

    public function test_can_revalue_foreign_bank_account_loss(): void
    {
        // 1. Initial deposit of 1,000 USD @ 300.00 LKR = 300,000 LKR on 2026-06-01
        $deposit = FinCore::createJournalEntry([
            'date'          => '2026-06-01',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'lines'         => [
                ['account_id' => $this->usdBank->id, 'type' => 'debit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
                ['account_id' => $this->equity->id, 'type' => 'credit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
            ],
        ]);
        $deposit->post();

        // 2. Revalue on 2026-06-30 at closing rate 280.00 LKR (-20 LKR per USD -> 20,000 LKR Loss)
        $result = FinCore::revalueForexBalances(
            asOfDate: '2026-06-30',
            closingRates: ['USD' => 280.0],
            autoReverse: false
        );

        $this->assertEquals(0.0, $result['total_gain']);
        $this->assertEquals(20000.0, $result['total_loss']);
        $this->assertEquals(-20000.0, $result['net_gain_loss']);
        $this->assertNull($result['reversal_entry']);

        // Bank functional balance is reduced to 280,000 LKR
        $this->assertEquals(280000.0, $this->usdBank->getCurrentBalance(endDate: '2026-06-30'));
    }

    public function test_can_revalue_ar_and_ap_at_closing_exchange_rates(): void
    {
        // 1. Foreign sale on AR: 500 USD @ 300.00 = 150,000 LKR
        $sale = FinCore::createJournalEntry([
            'date'          => '2026-06-10',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'lines'         => [
                ['account_id' => $this->arAccount->id, 'type' => 'debit', 'fc_amount' => 500.0, 'amount' => 150000.0],
                ['account_id' => $this->salesRevenue->id, 'type' => 'credit', 'fc_amount' => 500.0, 'amount' => 150000.0],
            ],
        ]);
        $sale->post();

        // 2. Foreign purchase on AP: 400 USD @ 300.00 = 120,000 LKR
        $purchase = FinCore::createJournalEntry([
            'date'          => '2026-06-15',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'lines'         => [
                ['account_id' => $this->cogsExpense->id, 'type' => 'debit', 'fc_amount' => 400.0, 'amount' => 120000.0],
                ['account_id' => $this->apAccount->id, 'type' => 'credit', 'fc_amount' => 400.0, 'amount' => 120000.0],
            ],
        ]);
        $purchase->post();

        // Revalue at 310.00 LKR on 2026-06-30:
        // AR: 500 USD @ 310 = 155,000 LKR (+5,000 Gain on Asset)
        // AP: 400 USD @ 310 = 124,000 LKR (+4,000 Loss on Liability)
        $result = FinCore::revalueForexBalances(
            asOfDate: '2026-06-30',
            closingRates: ['USD' => 310.0],
            autoReverse: true
        );

        $this->assertEquals(5000.0, $result['total_gain']);
        $this->assertEquals(4000.0, $result['total_loss']);
        $this->assertEquals(1000.0, $result['net_gain_loss']);

        // AR balance as of June 30 is 155,000 LKR
        $this->assertEquals(155000.0, $this->arAccount->getCurrentBalance(endDate: '2026-06-30'));
        // AP balance as of June 30 is 124,000 LKR
        $this->assertEquals(124000.0, $this->apAccount->getCurrentBalance(endDate: '2026-06-30'));
    }

    public function test_non_monetary_accounts_are_strictly_excluded_from_revaluation(): void
    {
        // Post foreign transactions to inventory, fixed asset, and equity
        $entry = FinCore::createJournalEntry([
            'date'          => '2026-06-05',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'lines'         => [
                ['account_id' => $this->inventory->id, 'type' => 'debit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
                ['account_id' => $this->fixedAsset->id, 'type' => 'debit', 'fc_amount' => 2000.0, 'amount' => 600000.0],
                ['account_id' => $this->equity->id, 'type' => 'credit', 'fc_amount' => 3000.0, 'amount' => 900000.0],
            ],
        ]);
        $entry->post();

        // Revalue at 350.00
        $result = FinCore::revalueForexBalances(
            asOfDate: '2026-06-30',
            closingRates: ['USD' => 350.0]
        );

        // Must find NO monetary items to revalue (all non-monetary accounts excluded)
        $this->assertEmpty($result['items']);
        $this->assertEquals(0.0, $result['total_gain']);
        $this->assertEquals(0.0, $result['total_loss']);
        $this->assertNull($result['journal_entry']);
    }

    public function test_auto_reversal_uses_subsequent_fiscal_period_start_date(): void
    {
        // Set up fiscal year and two periods
        $fy = FiscalYear::create([
            'name'       => 'FY-2026',
            'start_date' => '2026-01-01',
            'end_date'   => '2026-12-31',
            'is_current' => true,
        ]);

        FiscalPeriod::create([
            'fiscal_year_id' => $fy->id,
            'name'           => 'Period 06 (June)',
            'start_date'     => '2026-06-01',
            'end_date'       => '2026-06-30',
        ]);

        FiscalPeriod::create([
            'fiscal_year_id' => $fy->id,
            'name'           => 'Period 07 (July)',
            'start_date'     => '2026-07-01',
            'end_date'       => '2026-07-31',
        ]);

        $deposit = FinCore::createJournalEntry([
            'date'          => '2026-06-15',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'lines'         => [
                ['account_id' => $this->usdBank->id, 'type' => 'debit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
                ['account_id' => $this->equity->id, 'type' => 'credit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
            ],
        ]);
        $deposit->post();

        $result = FinCore::revalueForexBalances(
            asOfDate: '2026-06-30',
            closingRates: ['USD' => 320.0],
            autoReverse: true
        );

        $this->assertEquals('2026-07-01', $result['reversal_entry']->date->format('Y-m-d'));
    }

    public function test_manual_reverse_revaluation_entry(): void
    {
        $deposit = FinCore::createJournalEntry([
            'date'          => '2026-06-15',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'lines'         => [
                ['account_id' => $this->usdBank->id, 'type' => 'debit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
                ['account_id' => $this->equity->id, 'type' => 'credit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
            ],
        ]);
        $deposit->post();

        // Revalue without autoReverse
        $result = FinCore::revalueForexBalances(
            asOfDate: '2026-06-30',
            closingRates: ['USD' => 320.0],
            autoReverse: false
        );

        $revalEntry = $result['journal_entry'];
        $this->assertNotNull($revalEntry);
        $this->assertNull($result['reversal_entry']);

        // Manually reverse
        $reversal = FinCore::reverseForexRevaluationEntry($revalEntry, '2026-07-01');
        $this->assertEquals('2026-07-01', $reversal->date->format('Y-m-d'));
        $this->assertEquals(JvStatus::POSTED, $reversal->status);

        // Attempting to reverse again throws exception
        $this->expectException(AccountingException::class);
        FinCore::reverseForexRevaluationEntry($revalEntry);
    }

    public function test_calculate_preview_does_not_modify_database(): void
    {
        $deposit = FinCore::createJournalEntry([
            'date'          => '2026-06-15',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'lines'         => [
                ['account_id' => $this->usdBank->id, 'type' => 'debit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
                ['account_id' => $this->equity->id, 'type' => 'credit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
            ],
        ]);
        $deposit->post();

        $initialEntryCount = JournalEntry::count();

        $preview = FinCore::calculateForexRevaluation('2026-06-30', ['USD' => 330.0]);

        $this->assertEquals(30000.0, $preview['total_gain']);
        $this->assertCount(1, $preview['items']);
        // Verify database was not changed
        $this->assertEquals($initialEntryCount, JournalEntry::count());
    }

    public function test_revaluation_reflects_in_balance_sheet_and_income_statement(): void
    {
        // 1. Initial USD deposit of $1,000 @ 300 LKR = 300,000 LKR
        $deposit = FinCore::createJournalEntry([
            'date'          => '2026-06-01',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'lines'         => [
                ['account_id' => $this->usdBank->id, 'type' => 'debit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
                ['account_id' => $this->equity->id, 'type' => 'credit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
            ],
        ]);
        $deposit->post();

        // 2. Revalue on 2026-06-30 at 320 LKR (Gain of 20,000 LKR) with autoReverse on 2026-07-01
        FinCore::revalueForexBalances(
            asOfDate: '2026-06-30',
            closingRates: ['USD' => 320.0],
            autoReverse: true
        );

        // 3. Balance Sheet at 2026-06-30 should reflect 320,000 LKR in Assets and be balanced
        $bsJune = FinCore::getBalanceSheet('2026-06-30');
        $this->assertTrue($bsJune['is_balanced']);
        $this->assertEquals(320000.0, $bsJune['assets']['total']);
        $this->assertEquals(20000.0, $bsJune['current_year_earnings']);

        // 4. Income Statement for June should show 20,000 LKR net income from unrealized gain
        $isJune = FinCore::getIncomeStatement('2026-06-01', '2026-06-30');
        $this->assertEquals(20000.0, $isJune['net_income']);

        // 5. Balance Sheet at 2026-07-01 should reflect reversal back to 300,000 LKR and remain balanced
        $bsJuly = FinCore::getBalanceSheet('2026-07-01');
        $this->assertTrue($bsJuly['is_balanced']);
        $this->assertEquals(300000.0, $bsJuly['assets']['total']);
        $this->assertEquals(0.0, $bsJuly['current_year_earnings']);
    }

    public function test_multi_currency_batch_revaluation(): void
    {
        $eurBank = Account::create([
            'code'      => '1102',
            'name'      => 'EUR Bank Account',
            'type'      => AccountType::ASSET,
            'subtype'   => 'current_asset',
            'currency'  => 'EUR',
            'is_active' => true,
        ]);

        // USD deposit: 1000 USD @ 300 = 300,000 LKR
        $usdDeposit = FinCore::createJournalEntry([
            'date'          => '2026-06-01',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'lines'         => [
                ['account_id' => $this->usdBank->id, 'type' => 'debit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
                ['account_id' => $this->equity->id, 'type' => 'credit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
            ],
        ]);
        $usdDeposit->post();

        // EUR deposit: 500 EUR @ 320 = 160,000 LKR
        $eurDeposit = FinCore::createJournalEntry([
            'date'          => '2026-06-01',
            'currency'      => 'EUR',
            'exchange_rate' => 320.0,
            'lines'         => [
                ['account_id' => $eurBank->id, 'type' => 'debit', 'fc_amount' => 500.0, 'amount' => 160000.0],
                ['account_id' => $this->equity->id, 'type' => 'credit', 'fc_amount' => 500.0, 'amount' => 160000.0],
            ],
        ]);
        $eurDeposit->post();

        // Revalue both USD and EUR at period end
        $result = FinCore::revalueForexBalances(
            asOfDate: '2026-06-30',
            closingRates: [
                'USD' => 310.0, // +10 LKR * 1000 = +10,000 LKR
                'EUR' => 340.0, // +20 LKR * 500 = +10,000 LKR
            ],
            autoReverse: true
        );

        $this->assertEquals(20000.0, $result['total_gain']);
        $this->assertCount(2, $result['journal_entries']);
        $this->assertCount(2, $result['reversal_entries']);

        // Check balances as of June 30
        $this->assertEquals(310000.0, $this->usdBank->getCurrentBalance(endDate: '2026-06-30'));
        $this->assertEquals(170000.0, $eurBank->getCurrentBalance(endDate: '2026-06-30'));
    }

    public function test_cannot_revalue_with_zero_or_negative_rate(): void
    {
        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage("must be greater than zero");

        FinCore::calculateForexRevaluation('2026-06-30', ['USD' => 0.0]);
    }

    public function test_cannot_duplicate_revaluation_for_same_period_and_currency(): void
    {
        $deposit = FinCore::createJournalEntry([
            'date'          => '2026-06-01',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'lines'         => [
                ['account_id' => $this->usdBank->id, 'type' => 'debit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
                ['account_id' => $this->equity->id, 'type' => 'credit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
            ],
        ]);
        $deposit->post();

        FinCore::revalueForexBalances('2026-06-30', ['USD' => 320.0]);

        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage("already exists");

        // Running duplicate revaluation without voiding previous
        FinCore::revalueForexBalances('2026-06-30', ['USD' => 325.0]);
    }

    public function test_revaluation_filtered_by_sbu_code(): void
    {
        // Deposit with SBU-A
        $depositA = FinCore::createJournalEntry([
            'date'          => '2026-06-01',
            'currency'      => 'USD',
            'exchange_rate' => 300.0,
            'sbu_code'      => 'SBU-A',
            'lines'         => [
                ['account_id' => $this->usdBank->id, 'type' => 'debit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
                ['account_id' => $this->equity->id, 'type' => 'credit', 'fc_amount' => 1000.0, 'amount' => 300000.0],
            ],
        ]);
        $depositA->post();

        // Preview revaluation for SBU-A
        $previewA = FinCore::calculateForexRevaluation('2026-06-30', ['USD' => 320.0], sbuCode: 'SBU-A');
        $this->assertEquals(20000.0, $previewA['total_gain']);

        // Preview revaluation for SBU-B (no transactions)
        $previewB = FinCore::calculateForexRevaluation('2026-06-30', ['USD' => 320.0], sbuCode: 'SBU-B');
        $this->assertEquals(0.0, $previewB['total_gain']);
        $this->assertEmpty($previewB['items']);
    }
}
