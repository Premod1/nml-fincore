<?php

namespace Nml\FinCore\Tests\Unit;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Exceptions\AccountingException;
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\JournalEntry;
use Nml\FinCore\Tests\TestCase;

class DoubleEntryTest extends TestCase
{
    private Account $cashAccount;
    private Account $revenueAccount;

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

        $this->revenueAccount = Account::create([
            'code'      => '4000',
            'name'      => 'Sales Revenue',
            'type'      => AccountType::REVENUE,
            'subtype'   => 'revenue',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);
    }

    public function test_can_create_and_post_balanced_journal_entry(): void
    {
        $entry = FinCore::createJournalEntry([
            'date'        => '2026-06-01',
            'description' => 'Test Sales Entry',
            'lines'       => [
                [
                    'account_id' => $this->cashAccount->id,
                    'type'       => 'debit',
                    'amount'     => 5000.00,
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'type'       => 'credit',
                    'amount'     => 5000.00,
                ],
            ],
        ]);

        $this->assertEquals(JvStatus::DRAFT, $entry->status);
        $this->assertStringStartsWith('JE-2026-', $entry->entry_number);

        $entry->post();

        $this->assertEquals(JvStatus::POSTED, $entry->fresh()->status);
        $this->assertEquals(5000.00, $this->cashAccount->getCurrentBalance());
        $this->assertEquals(5000.00, $this->revenueAccount->getCurrentBalance());
    }

    public function test_cannot_post_unbalanced_journal_entry(): void
    {
        $entry = FinCore::createJournalEntry([
            'date'  => '2026-06-01',
            'lines' => [
                [
                    'account_id' => $this->cashAccount->id,
                    'type'       => 'debit',
                    'amount'     => 5000.00,
                ],
                [
                    'account_id' => $this->revenueAccount->id,
                    'type'       => 'credit',
                    'amount'     => 4000.00, // Unbalanced by 1000
                ],
            ],
        ]);

        $this->expectException(AccountingException::class);
        $entry->post();
    }

    public function test_cannot_modify_posted_journal_entry(): void
    {
        $entry = FinCore::createJournalEntry([
            'date'  => '2026-06-01',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 1000.00],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 1000.00],
            ],
        ]);
        $entry->post();

        $this->expectException(AccountingException::class);
        $entry->update(['date' => '2026-01-01']);
    }

    public function test_cannot_delete_posted_journal_entry(): void
    {
        $entry = FinCore::createJournalEntry([
            'date'  => '2026-06-01',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 1000.00],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 1000.00],
            ],
        ]);
        $entry->post();

        $this->expectException(AccountingException::class);
        $entry->delete();
    }

    public function test_cannot_delete_or_modify_lines_of_posted_journal_entry(): void
    {
        $entry = FinCore::createJournalEntry([
            'date'  => '2026-06-01',
            'lines' => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 1000.00],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 1000.00],
            ],
        ]);
        $entry->post();

        $line = $entry->lines()->first();

        $this->expectException(AccountingException::class);
        $line->update(['amount' => 9999.00]);
    }
}
