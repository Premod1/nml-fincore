# NML FinCore

NML FinCore is a robust, decoupled double-entry accounting engine designed for Laravel applications. It enables real-time ledger generation, polymorphic linking to transactional models, multi-SBU reporting, and strict period locks.

---

## Installation

Install the package via Composer:

```bash
composer require nml/fincore
```

### Migration Setup

The package migrations will be loaded automatically. Run the database migrations to create the necessary tables:

```bash
php artisan migrate
```

### Configuration (Optional)

Publish the configuration file to customize the table prefix, rounding tolerance, currency, and period locks:

```bash
php artisan vendor:publish --tag=fincore-config
```

---

## Core Feature List

* **Double-Entry General Ledger Engine**: Enforces exact balance matching for debit and credit lines within a configurable rounding tolerance.
* **Polymorphic Transactions**: Links journal entries directly to external source models such as Invoices, Sales, Purchases, or Payments.
* **Dynamic Chart of Accounts**: Classifies and rolls up accounts hierarchically under Assets, Liabilities, Equity, Revenue, and Expenses.
* **Real-time Financial Reporting**: Outputs General Ledger, Trial Balance, Balance Sheet, Income Statement (P&L), and Cash Flow statement dynamically based on date range and SBU codes.
* **Multi-SBU Segment Reporting**: Tracks entries using Strategic Business Unit (SBU) codes for departmental or branch-level accounting.
* **Fiscal Period Management**: Supports locking accounting periods to prevent retrospective entries.

---

## Initialization

Seed the standard Chart of Accounts using the provided seeder or calling the initializer class directly:

### Option A: Using Laravel Database Seeder
Add the package seeder class to your main `database/seeders/DatabaseSeeder.php` file:

```php
public function run(): void
{
    $this->call(\Nml\FinCore\Database\Seeders\FinCoreSeeder::class);
}
```

Or run it directly from the terminal:

```bash
php artisan db:seed --class="Nml\FinCore\Database\Seeders\FinCoreSeeder"
```

### Option B: Programmatic Initialization
You can seed the standard Chart of Accounts programmatically (e.g., in a tenant setup flow or custom script):

```php
use Nml\FinCore\Services\ChartOfAccountsInitializer;

// Seeds the default chart of accounts (idempotent, safe to rerun)
ChartOfAccountsInitializer::initialize('LKR');
```

---

## Usage Scenarios

### 1. Creating and Posting a Journal Entry

Create a journal entry as a draft, validate it, and post it to the General Ledger.

```php
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Enums\JvType;

// Create the journal entry draft
$entry = FinCore::createJournalEntry([
    'date' => '2026-06-18',
    'reference' => 'INV-2026-0001',
    'type' => JvType::GENERAL->value,
    'description' => 'Credit sale invoice registration',
    'sbu_code' => 'COLOMBO_SBU',
    'journalable_type' => 'App\Models\Invoice', // Optional polymorphic model link
    'journalable_id' => 45,
    'lines' => [
        [
            'account_id' => 3, // Accounts Receivable (Asset)
            'type' => 'debit',
            'amount' => 50000.00,
            'description' => 'Debit A/R for customer invoice'
        ],
        [
            'account_id' => 20, // Sales Revenue (Revenue)
            'type' => 'credit',
            'amount' => 50000.00,
            'description' => 'Credit Sales Revenue'
        ]
    ]
]);

// Post to General Ledger (Performs balance matching & period lock checks)
try {
    $entry->post();
} catch (\Nml\FinCore\Exceptions\AccountingException $e) {
    // Handle validation errors (e.g., Unbalanced Debits/Credits or Closed Period)
    Log::error($e->getMessage());
}
```

### 2. Generating a Trial Balance

Generate a Trial Balance report within a specific date range or filter by SBU.

```php
use Nml\FinCore\Facades\FinCore;

$trialBalance = FinCore::getTrialBalance(
    startDate: '2026-01-01',
    endDate: '2026-06-30',
    sbuCode: 'COLOMBO_SBU' // Optional SBU filter
);

// Format of the returned array:
// [
//     'accounts' => [
//          ['account' => AccountModel, 'debit' => 50000.00, 'credit' => 0.00, 'balance' => 50000.00],
//          ...
//      ],
//     'total_debits' => 50000.00,
//     'total_credits' => 50000.00,
//     'is_balanced' => true
// ]
```

### 3. Generating an Income Statement (Profit & Loss)

Get the revenue, expense, and net income metrics for a specified period.

```php
use Nml\FinCore\Facades\FinCore;

$incomeStatement = FinCore::getIncomeStatement(
    startDate: '2026-04-01',
    endDate: '2026-06-30'
);

$netIncome = $incomeStatement['net_income'];
$totalRevenue = $incomeStatement['revenue']['total'];
$totalExpenses = $incomeStatement['expenses']['total'];
```

### 4. Generating a Balance Sheet

Generate the statement of financial position as of a specific date.

```php
use Nml\FinCore\Facades\FinCore;

$balanceSheet = FinCore::getBalanceSheet(
    date: '2026-06-18'
);

$assets = $balanceSheet['assets']['total'];
$liabilities = $balanceSheet['liabilities']['total'];
$equity = $balanceSheet['equity']['total'];
$isBalanced = $balanceSheet['is_balanced']; // Assets = Liabilities + Equity
```

### 5. Generating a Cash Flow Statement

Generate a Cash Flow statement (indirect method) for a date range:

```php
use Nml\FinCore\Facades\FinCore;

$cashFlow = FinCore::getCashFlowStatement(
    startDate: '2026-01-01',
    endDate: '2026-06-30'
);

$operatingTotal = $cashFlow['operating_activities']['total'];
$investingTotal = $cashFlow['investing_activities']['total'];
$financingTotal = $cashFlow['financing_activities']['total'];
$netIncreaseDecrease = $cashFlow['net_increase_decrease'];
$beginningCash = $cashFlow['beginning_cash'];
$endingCash = $cashFlow['ending_cash'];
$isReconciled = $cashFlow['is_reconciled']; // true if beginningCash + netIncreaseDecrease matches endingCash
```

### 6. Managing Entry Statuses

Transitions draft entries to submitted, and void or reject them:

```php
// Submit for review
$entry->submit($userId);

// Reject and send back to draft
$entry->returnToDraft('Reason for rejection');

// Void posted journal entry
$entry->void();
```
