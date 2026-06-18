<?php

return [
    /*
     * Default currency for the general ledger.
     */
    'currency' => env('ACCOUNTING_CURRENCY', 'LKR'),

    /*
     * Rounding tolerance for double-entry validation.
     * Debits and Credits must balance within this amount.
     */
    'rounding_tolerance' => env('ACCOUNTING_ROUNDING_TOLERANCE', 0.005),

    /*
     * Enforce period locking.
     * Prevents posting journal entries to closed fiscal periods.
     */
    'enforce_period_lock' => env('ACCOUNTING_ENFORCE_PERIOD_LOCK', true),

    /*
     * Database table prefix to avoid conflicts with host application tables.
     */
    'table_prefix' => env('FINCORE_TABLE_PREFIX', 'fincore_'),

    /*
     * Account codes classified as Cash & Cash Equivalents for Cash Flow reporting.
     */
    'cash_account_codes' => ['1000', '1100'],

    /*
     * Account codes classified as Financing/Equity (loans, capital, etc.) for Cash Flow reporting.
     */
    'financing_account_codes' => ['2400', '2500', '3000'],

    /*
     * Account codes classified as Non-Cash Expenses (depreciation, etc.) for Cash Flow reporting.
     */
    'non_cash_expense_codes' => ['5500'],
];
