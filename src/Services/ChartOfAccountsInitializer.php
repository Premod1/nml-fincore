<?php

namespace Nml\FinCore\Services;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Models\Account;

class ChartOfAccountsInitializer
{
    /**
     * Seed the standard chart of accounts. Safe to re-run multiple times (idempotent).
     */
    public static function initialize(?string $currency = null): void
    {
        $currency = $currency ?: config('accounting.currency', 'LKR');
        $accounts = [
            // ASSETS
            [
                'code' => '1000',
                'name' => 'Cash on Hand',
                'type' => AccountType::ASSET,
                'subtype' => 'current_asset',
            ],
            [
                'code' => '1100',
                'name' => 'Bank Accounts',
                'type' => AccountType::ASSET,
                'subtype' => 'current_asset',
            ],
            [
                'code' => '1200',
                'name' => 'Accounts Receivable (A/R)',
                'type' => AccountType::ASSET,
                'subtype' => 'current_asset',
            ],
            [
                'code' => '1300',
                'name' => 'Merchandise Inventory',
                'type' => AccountType::ASSET,
                'subtype' => 'current_asset',
            ],
            [
                'code' => '1400',
                'name' => 'Prepaid Expenses',
                'type' => AccountType::ASSET,
                'subtype' => 'current_asset',
            ],
            [
                'code' => '1500',
                'name' => 'Fixed Assets',
                'type' => AccountType::ASSET,
                'subtype' => 'fixed_asset',
            ],
            [
                'code' => '1550',
                'name' => 'Accumulated Depreciation',
                'type' => AccountType::ASSET,
                'subtype' => 'fixed_asset',
            ],

            // LIABILITIES
            [
                'code' => '2000',
                'name' => 'Accounts Payable (A/P)',
                'type' => AccountType::LIABILITY,
                'subtype' => 'current_liability',
            ],
            [
                'code' => '2150',
                'name' => 'Inventory Financing Payable',
                'type' => AccountType::LIABILITY,
                'subtype' => 'current_liability',
            ],
            [
                'code' => '2170',
                'name' => 'Accrued Financing Interest',
                'type' => AccountType::LIABILITY,
                'subtype' => 'current_liability',
            ],
            [
                'code' => '2300',
                'name' => 'Sales Tax (VAT) Payable',
                'type' => AccountType::LIABILITY,
                'subtype' => 'current_liability',
            ],
            [
                'code' => '2400',
                'name' => 'Short-term Loans Payable',
                'type' => AccountType::LIABILITY,
                'subtype' => 'current_liability',
            ],
            [
                'code' => '2500',
                'name' => 'Long-term Loans Payable',
                'type' => AccountType::LIABILITY,
                'subtype' => 'non_current_liability',
            ],

            // EQUITY
            [
                'code' => '3000',
                'name' => 'Share Capital',
                'type' => AccountType::EQUITY,
                'subtype' => 'equity',
            ],
            [
                'code' => '3099',
                'name' => 'Opening Balance Equity',
                'type' => AccountType::EQUITY,
                'subtype' => 'equity',
            ],
            [
                'code' => '3100',
                'name' => 'Retained Earnings',
                'type' => AccountType::EQUITY,
                'subtype' => 'equity',
            ],
            [
                'code' => '3900',
                'name' => 'Income Summary',
                'type' => AccountType::EQUITY,
                'subtype' => 'equity',
            ],

            // REVENUE
            [
                'code' => '4000',
                'name' => 'Sales Revenue',
                'type' => AccountType::REVENUE,
                'subtype' => 'revenue',
            ],

            // EXPENSES
            [
                'code' => '5000',
                'name' => 'Cost of Goods Sold (COGS)',
                'type' => AccountType::EXPENSE,
                'subtype' => 'expense',
            ],
            [
                'code' => '5400',
                'name' => 'Office Supplies',
                'type' => AccountType::EXPENSE,
                'subtype' => 'expense',
            ],
            [
                'code' => '5500',
                'name' => 'Purchase Discounts',
                'type' => AccountType::EXPENSE,
                'subtype' => 'expense',
            ],
            [
                'code' => '6130',
                'name' => 'Sales Discounts',
                'type' => AccountType::EXPENSE,
                'subtype' => 'expense',
            ],
            [
                'code' => '6100',
                'name' => 'Electricity & Utilities Expense',
                'type' => AccountType::EXPENSE,
                'subtype' => 'expense',
            ],
            [
                'code' => '6200',
                'name' => 'Rent Expense',
                'type' => AccountType::EXPENSE,
                'subtype' => 'expense',
            ],
            [
                'code' => '6300',
                'name' => 'Salaries & Wages Expense',
                'type' => AccountType::EXPENSE,
                'subtype' => 'expense',
            ],
            [
                'code' => '6400',
                'name' => 'Telephone & Internet Expense',
                'type' => AccountType::EXPENSE,
                'subtype' => 'expense',
            ],
            [
                'code' => '6710',
                'name' => 'Interest Expense - Inventory Fin.',
                'type' => AccountType::EXPENSE,
                'subtype' => 'expense',
            ],
            [
                'code' => '6720',
                'name' => 'Interest Expense - Short-term',
                'type' => AccountType::EXPENSE,
                'subtype' => 'expense',
            ],
            [
                'code' => '6730',
                'name' => 'Interest Expense - Long-term',
                'type' => AccountType::EXPENSE,
                'subtype' => 'expense',
            ],
        ];

        foreach ($accounts as $acc) {
            Account::firstOrCreate(
                ['code' => $acc['code']],
                [
                    'name' => $acc['name'],
                    'type' => $acc['type'],
                    'subtype' => $acc['subtype'],
                    'currency' => $currency,
                    'is_active' => true,
                ]
            );
        }
    }
}
