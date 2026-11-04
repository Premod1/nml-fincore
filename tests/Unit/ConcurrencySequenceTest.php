<?php

namespace Nml\FinCore\Tests\Unit;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\JournalEntry;
use Nml\FinCore\Tests\TestCase;

class ConcurrencySequenceTest extends TestCase
{
    public function test_entry_number_sequence_avoids_collisions_and_gaps_on_deletion(): void
    {
        $cash = Account::create([
            'code'      => '1000',
            'name'      => 'Cash',
            'type'      => AccountType::ASSET,
            'subtype'   => 'current_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $rev = Account::create([
            'code'      => '4000',
            'name'      => 'Revenue',
            'type'      => AccountType::REVENUE,
            'subtype'   => 'revenue',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $lines = [
            ['account_id' => $cash->id, 'type' => 'debit', 'amount' => 100.00],
            ['account_id' => $rev->id, 'type' => 'credit', 'amount' => 100.00],
        ];

        // Create 3 draft entries
        $e1 = FinCore::createJournalEntry(['date' => '2026-06-01', 'lines' => $lines]);
        $e2 = FinCore::createJournalEntry(['date' => '2026-06-02', 'lines' => $lines]);
        $e3 = FinCore::createJournalEntry(['date' => '2026-06-03', 'lines' => $lines]);

        $this->assertEquals('JE-2026-00001', $e1->entry_number);
        $this->assertEquals('JE-2026-00002', $e2->entry_number);
        $this->assertEquals('JE-2026-00003', $e3->entry_number);

        // Delete middle entry #2 (draft entry can be deleted)
        $e2->delete();

        // Creating next entry should NOT collide with #3, it must become #4
        $e4 = FinCore::createJournalEntry(['date' => '2026-06-04', 'lines' => $lines]);
        $this->assertEquals('JE-2026-00004', $e4->entry_number);
    }
}
