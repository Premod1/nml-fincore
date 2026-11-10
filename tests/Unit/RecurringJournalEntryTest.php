<?php

namespace Nml\FinCore\Tests\Unit;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\JournalEntry;
use Nml\FinCore\Models\RecurringJournalEntry;
use Nml\FinCore\Tests\TestCase;

class RecurringJournalEntryTest extends TestCase
{
    private Account $cashAccount;
    private Account $rentExpense;
    private Account $prepaidRent;
    private Account $revenueAccount;
    private Account $loanPayable;
    private Account $interestExpense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashAccount = Account::create([
            'code'      => '1000',
            'name'      => 'Cash',
            'type'      => AccountType::ASSET,
            'subtype'   => 'current_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->prepaidRent = Account::create([
            'code'      => '1400',
            'name'      => 'Prepaid Rent',
            'type'      => AccountType::ASSET,
            'subtype'   => 'current_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->rentExpense = Account::create([
            'code'      => '6200',
            'name'      => 'Rent Expense',
            'type'      => AccountType::EXPENSE,
            'subtype'   => 'expense',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->revenueAccount = Account::create([
            'code'      => '4000',
            'name'      => 'Subscription Revenue',
            'type'      => AccountType::REVENUE,
            'subtype'   => 'revenue',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->loanPayable = Account::create([
            'code'      => '2400',
            'name'      => 'Short-term Loan',
            'type'      => AccountType::LIABILITY,
            'subtype'   => 'current_liability',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->interestExpense = Account::create([
            'code'      => '6720',
            'name'      => 'Interest Expense',
            'type'      => AccountType::EXPENSE,
            'subtype'   => 'expense',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);
    }

    public function test_can_create_recurring_journal_entry_template(): void
    {
        $recurring = FinCore::createRecurringEntry([
            'name'           => 'Monthly Office Rent',
            'code'           => 'REC-RENT',
            'frequency'      => 'monthly',
            'interval'       => 1,
            'start_date'     => '2026-01-01',
            'auto_post'      => true,
            'max_iterations' => 12,
            'lines_template' => [
                [
                    'account_id'  => $this->rentExpense->id,
                    'type'        => 'debit',
                    'amount'      => 50000.00,
                    'description' => 'Office Rent for {month_name} {year}',
                ],
                [
                    'account_id'  => $this->cashAccount->id,
                    'type'        => 'credit',
                    'amount'      => 50000.00,
                    'description' => 'Payment for Office Rent',
                ],
            ],
        ]);

        $this->assertInstanceOf(RecurringJournalEntry::class, $recurring);
        $this->assertEquals('Monthly Office Rent', $recurring->name);
        $this->assertEquals('REC-RENT', $recurring->code);
        $this->assertEquals('2026-01-01', $recurring->next_run_date->format('Y-m-d'));
        $this->assertEquals(0, $recurring->iteration_count);
        $this->assertTrue($recurring->is_active);
    }

    public function test_can_process_due_recurring_entry_with_fixed_amounts(): void
    {
        $recurring = FinCore::createRecurringEntry([
            'name'           => 'Monthly Office Rent',
            'code'           => 'REC-RENT',
            'frequency'      => 'monthly',
            'start_date'     => '2026-01-01',
            'auto_post'      => true,
            'max_iterations' => 12,
            'lines_template' => [
                [
                    'account_id'  => $this->rentExpense->id,
                    'type'        => 'debit',
                    'amount'      => 50000.00,
                    'description' => 'Office Rent for {month_name} {year}',
                ],
                [
                    'account_id'  => $this->cashAccount->id,
                    'type'        => 'credit',
                    'amount'      => 50000.00,
                    'description' => 'Payment for Office Rent',
                ],
            ],
        ]);

        // Process entry as of 2026-01-01
        $result = FinCore::processDueRecurringEntries('2026-01-01');

        $this->assertEquals(1, $result['processed_count']);
        /** @var JournalEntry $generated */
        $generated = $result['entries'][0];
        $this->assertEquals(JvStatus::POSTED, $generated->status);
        $this->assertEquals('REC-RENT-001', $generated->reference);
        $this->assertEquals(50000.00, $generated->getDebitSum());
        $this->assertEquals('2026-01-01', $generated->date->format('Y-m-d'));

        // Check line description interpolation
        $line = $generated->lines()->where('account_id', $this->rentExpense->id)->first();
        $this->assertEquals('Office Rent for January 2026', $line->description);

        // Check recurring template state updated
        $recurring->refresh();
        $this->assertEquals(1, $recurring->iteration_count);
        $this->assertEquals('2026-02-01', $recurring->next_run_date->format('Y-m-d'));
        $this->assertEquals('active', $recurring->status);

        // Postings verified in ledger
        $this->assertEquals(50000.00, $this->rentExpense->getCurrentBalance());
        $this->assertEquals(-50000.00, $this->cashAccount->getCurrentBalance());
    }

    public function test_draft_mode_when_auto_post_is_false(): void
    {
        $recurring = FinCore::createRecurringEntry([
            'name'           => 'Draft Subscription Billing',
            'frequency'      => 'monthly',
            'start_date'     => '2026-01-01',
            'auto_post'      => false,
            'lines_template' => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 10000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 10000.0],
            ],
        ]);

        $result = FinCore::processDueRecurringEntries('2026-01-01');
        $this->assertEquals(1, $result['processed_count']);

        /** @var JournalEntry $generated */
        $generated = $result['entries'][0];
        $this->assertEquals(JvStatus::DRAFT, $generated->status);

        // Not yet reflected in posted ledger balance
        $this->assertEquals(0.0, $this->revenueAccount->getCurrentBalance());
    }

    public function test_dynamic_formula_based_recurring_entries(): void
    {
        // 1-year prepaid rent amortization of 120,000 LKR across 12 months
        $recurring = FinCore::createRecurringEntry([
            'name'           => 'Prepaid Rent Amortization',
            'code'           => 'AMORT-RENT',
            'frequency'      => 'monthly',
            'start_date'     => '2026-01-01',
            'auto_post'      => true,
            'max_iterations' => 12,
            'metadata'       => [
                'total_amount' => 120000.00,
            ],
            'lines_template' => [
                [
                    'account_id'     => $this->rentExpense->id,
                    'type'           => 'debit',
                    'amount_formula' => 'total_amount / max_iterations',
                    'description'    => 'Rent Amortization Month {iteration}/{max_iterations} - {month_name} {year}',
                ],
                [
                    'account_id'     => $this->prepaidRent->id,
                    'type'           => 'credit',
                    'amount_formula' => 'total_amount / max_iterations',
                    'description'    => 'Prepaid Rent Amortization Month {iteration}/{max_iterations}',
                ],
            ],
        ]);

        // Process Month 1 (January)
        $entry1 = FinCore::processRecurringEntry($recurring, '2026-01-01');
        $this->assertNotNull($entry1);
        $this->assertEquals(10000.00, $entry1->getDebitSum()); // 120,000 / 12 = 10,000
        $this->assertEquals('Rent Amortization Month 1/12 - January 2026', $entry1->lines->first()->description);

        // Process Month 2 (February)
        $entry2 = FinCore::processRecurringEntry($recurring, '2026-02-01');
        $this->assertNotNull($entry2);
        $this->assertEquals(10000.00, $entry2->getDebitSum());
        $this->assertEquals('Rent Amortization Month 2/12 - February 2026', $entry2->lines->first()->description);

        $this->assertEquals(20000.00, $this->rentExpense->getCurrentBalance());
    }

    public function test_dynamic_balance_formula_recurring_entry(): void
    {
        // Set loan balance to 240,000 LKR
        $initialLoan = FinCore::createJournalEntry([
            'date'  => '2026-01-01',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 240000.00],
                ['account_id' => $this->loanPayable->id, 'type' => 'credit', 'amount' => 240000.00],
            ],
        ]);
        $initialLoan->post();

        // Recurring monthly interest accrual: 5% annual interest on loan balance = (balance * 0.05) / 12
        // On 240,000, monthly interest is 240,000 * 0.05 / 12 = 1,000.00
        $recurring = FinCore::createRecurringEntry([
            'name'           => 'Monthly Loan Interest Accrual',
            'frequency'      => 'monthly',
            'start_date'     => '2026-01-31',
            'auto_post'      => true,
            'lines_template' => [
                [
                    'account_id'     => $this->interestExpense->id,
                    'type'           => 'debit',
                    'amount_formula' => "balance('2400') * 0.05 / 12",
                    'description'    => 'Monthly interest accrual for {month_name} {year}',
                ],
                [
                    'account_id'     => $this->loanPayable->id,
                    'type'           => 'credit',
                    'amount_formula' => "balance('2400') * 0.05 / 12",
                    'description'    => 'Accrued interest added to loan',
                ],
            ],
        ]);

        $entry = FinCore::processRecurringEntry($recurring, '2026-01-31');
        $this->assertNotNull($entry);
        $this->assertEquals(1000.00, $entry->getDebitSum());
        $this->assertEquals(1000.00, $this->interestExpense->getCurrentBalance());
    }

    public function test_execution_tracking_iteration_limits(): void
    {
        $recurring = FinCore::createRecurringEntry([
            'name'           => 'Limited 3-Month Subscription',
            'frequency'      => 'monthly',
            'start_date'     => '2026-01-01',
            'max_iterations' => 2, // Only runs 2 times
            'lines_template' => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 5000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 5000.0],
            ],
        ]);

        // Run 1
        $e1 = FinCore::processRecurringEntry($recurring, '2026-01-01');
        $this->assertNotNull($e1);
        $recurring->refresh();
        $this->assertEquals(1, $recurring->iteration_count);
        $this->assertEquals('active', $recurring->status);

        // Run 2 (reaches max_iterations of 2)
        $e2 = FinCore::processRecurringEntry($recurring, '2026-02-01');
        $this->assertNotNull($e2);
        $recurring->refresh();
        $this->assertEquals(2, $recurring->iteration_count);
        $this->assertEquals('completed', $recurring->status);
        $this->assertFalse($recurring->is_active);

        // Run 3 should return null and not create any entry
        $e3 = FinCore::processRecurringEntry($recurring, '2026-03-01');
        $this->assertNull($e3);
    }

    public function test_expiration_date_tracking(): void
    {
        $recurring = FinCore::createRecurringEntry([
            'name'           => 'Expiring Recurring Entry',
            'frequency'      => 'monthly',
            'start_date'     => '2026-01-01',
            'end_date'       => '2026-02-15', // Expires in mid-February
            'lines_template' => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 5000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 5000.0],
            ],
        ]);

        // Jan 01: successfully processes
        $e1 = FinCore::processRecurringEntry($recurring, '2026-01-01');
        $this->assertNotNull($e1);
        $recurring->refresh();
        // next_run_date is 2026-02-01 (<= end_date 2026-02-15)
        $this->assertEquals('2026-02-01', $recurring->next_run_date->format('Y-m-d'));

        // Feb 01: successfully processes
        $e2 = FinCore::processRecurringEntry($recurring, '2026-02-01');
        $this->assertNotNull($e2);
        $recurring->refresh();
        // Next schedule would be 2026-03-01, which is > end_date (2026-02-15)
        // So template is automatically marked completed!
        $this->assertEquals('completed', $recurring->status);
        $this->assertFalse($recurring->is_active);

        // Attempting to run in March returns null
        $e3 = FinCore::processRecurringEntry($recurring, '2026-03-01');
        $this->assertNull($e3);
    }

    public function test_scheduling_intervals_daily_weekly_quarterly_annually_custom(): void
    {
        $engine = new \Nml\FinCore\Services\RecurringJournalEntryEngine();

        // Daily with interval 3
        $nextDaily = $engine->calculateNextRunDate('2026-01-01', 'daily', 3);
        $this->assertEquals('2026-01-04', $nextDaily);

        // Weekly with interval 2 (fortnightly)
        $nextWeekly = $engine->calculateNextRunDate('2026-01-01', 'weekly', 2);
        $this->assertEquals('2026-01-15', $nextWeekly);

        // Monthly with end-of-month handling (Jan 31 -> Feb 28)
        $nextMonthly = $engine->calculateNextRunDate('2026-01-31', 'monthly', 1);
        $this->assertEquals('2026-02-28', $nextMonthly);

        // Quarterly
        $nextQuarterly = $engine->calculateNextRunDate('2026-01-15', 'quarterly', 1);
        $this->assertEquals('2026-04-15', $nextQuarterly);

        // Annually
        $nextAnnually = $engine->calculateNextRunDate('2026-01-15', 'annually', 1);
        $this->assertEquals('2027-01-15', $nextAnnually);

        // Custom (e.g. 10 days)
        $nextCustom = $engine->calculateNextRunDate('2026-01-01', 'custom', 10);
        $this->assertEquals('2026-01-11', $nextCustom);
    }

    public function test_artisan_command_process_recurring(): void
    {
        FinCore::createRecurringEntry([
            'name'           => 'Scheduled Maintenance Fee',
            'code'           => 'REC-MAINT',
            'frequency'      => 'monthly',
            'start_date'     => '2026-06-01',
            'auto_post'      => true,
            'lines_template' => [
                ['account_id' => $this->rentExpense->id, 'type' => 'debit', 'amount' => 15000.0],
                ['account_id' => $this->cashAccount->id, 'type' => 'credit', 'amount' => 15000.0],
            ],
        ]);

        $this->artisan('fincore:process-recurring', ['--date' => '2026-06-01'])
            ->expectsOutputToContain('Processed 1 recurring journal entries')
            ->assertSuccessful();

        $this->assertEquals(15000.0, $this->rentExpense->getCurrentBalance());
    }

    public function test_pause_resume_and_cancel_recurring_entry(): void
    {
        $recurring = FinCore::createRecurringEntry([
            'name'           => 'Pausable Subscription',
            'frequency'      => 'monthly',
            'start_date'     => '2026-01-01',
            'lines_template' => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 2000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 2000.0],
            ],
        ]);

        // Pause
        $recurring->pause();
        $this->assertEquals('paused', $recurring->fresh()->status);
        $this->assertFalse($recurring->isDue('2026-01-01'));

        // Resume
        $recurring->resume();
        $this->assertEquals('active', $recurring->fresh()->status);
        $this->assertTrue($recurring->isDue('2026-01-01'));

        // Cancel
        $recurring->cancel();
        $this->assertEquals('cancelled', $recurring->fresh()->status);
        $this->assertFalse($recurring->fresh()->is_active);
        $this->assertFalse($recurring->isDue('2026-01-01'));
    }
}
