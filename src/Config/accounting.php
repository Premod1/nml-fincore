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
];
