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
    'non_cash_expense_codes' => ['6500'],

    /*
     * Account codes classified as Contra-Asset / Accumulated Depreciation for Cash Flow reporting.
     * Excluded from investing activities to avoid treating credited depreciation as cash inflow.
     */
    'accumulated_depreciation_codes' => ['1550'],

    /*
     * Account codes classified as Accounts Receivable for Ageing reporting.
     */
    'ar_account_codes' => ['1200'],

    /*
     * Account codes classified as Accounts Payable for Ageing reporting.
     */
    'ap_account_codes' => ['2000'],

    /*
     * Account codes classified as monetary accounts eligible for unrealized forex revaluation.
     * Cash/Bank, AR, AP, Short/Long term debt.
     */
    'monetary_account_codes' => ['1000', '1100', '1200', '2000', '2400', '2500'],

    /*
     * Account codes explicitly excluded as non-monetary (inventory, prepayments, fixed assets, equity).
     */
    'non_monetary_account_codes' => ['1300', '1400', '1500', '1550', '2100', '3000', '3099', '3100', '3900'],

    /*
     * Standard Chart of Accounts codes for Unrealized Foreign Exchange Gain and Loss.
     */
    'forex_gain_account_code' => '4900',
    'forex_loss_account_code' => '6900',
];
