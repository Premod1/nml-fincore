<?php

namespace Nml\FinCore\Tests\Unit;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Tests\TestCase;

class PartnerAgeingTest extends TestCase
{
    public function test_receivables_and_payables_ageing_report_generation(): void
    {
        $arAccount = Account::create([
            'code'      => '1200',
            'name'      => 'Accounts Receivable',
            'type'      => AccountType::ASSET,
            'subtype'   => 'current_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $revenueAccount = Account::create([
            'code'      => '4000',
            'name'      => 'Sales',
            'type'      => AccountType::REVENUE,
            'subtype'   => 'revenue',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $bankAccount = Account::create([
            'code'      => '1100',
            'name'      => 'Bank',
            'type'      => AccountType::ASSET,
            'subtype'   => 'current_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        // Invoice on 2026-05-01, due 2026-05-31: 10,000 for Customer #1
        $invoice = FinCore::createJournalEntry([
            'date'  => '2026-05-01',
            'lines' => [
                [
                    'account_id'       => $arAccount->id,
                    'type'             => 'debit',
                    'amount'           => 10000.00,
                    'due_date'         => '2026-05-31',
                    'partnerable_type' => 'App\Models\Customer',
                    'partnerable_id'   => 1,
                ],
                [
                    'account_id'       => $revenueAccount->id,
                    'type'             => 'credit',
                    'amount'           => 10000.00,
                ],
            ],
        ]);
        $invoice->post();

        // Partial payment on 2026-06-10: 4,000 from Customer #1
        $payment = FinCore::createJournalEntry([
            'date'  => '2026-06-10',
            'lines' => [
                [
                    'account_id'       => $bankAccount->id,
                    'type'             => 'debit',
                    'amount'           => 4000.00,
                ],
                [
                    'account_id'       => $arAccount->id,
                    'type'             => 'credit',
                    'amount'           => 4000.00,
                    'partnerable_type' => 'App\Models\Customer',
                    'partnerable_id'   => 1,
                ],
            ],
        ]);
        $payment->post();

        // As of 2026-06-30: remaining outstanding = 6,000
        // Due date: 2026-05-31. Age as of 2026-06-30 is 30 days -> bucket '1_30'
        $report = FinCore::getReceivablesAgeingReport('2026-06-30');

        $this->assertNotEmpty($report);
        $this->assertEquals(6000.00, $report[0]['total_outstanding']);
        $this->assertEquals(6000.00, $report[0]['1_30']);
    }
}
