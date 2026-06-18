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

#### Parameter Details

* **`type`**: Indicates the category of the journal entry. Developers should use the `Nml\FinCore\Enums\JvType` enum:
  * `JvType::GENERAL->value` (`'general'`): Normal business transactions (e.g., sales invoices, supplier purchases, payments).
  * `JvType::ADJUSTMENT->value` (`'adjustment'`): End-of-period adjustments (e.g., depreciation, accruals, prepayments).
  * `JvType::CLOSING->value` (`'closing'`): Year-end closing entries to reset revenue/expense balances to the retained earnings account.
* **`sbu_code`**: Strategic Business Unit (Branch, Department, or Division) code. Tagging entries with this code enables department-wise or branch-wise filtering on all financial statements.
* **`journalable_type` & `journalable_id`**: Polymorphic relationship columns that map this journal entry to the source document in the ERP (e.g., `App\Models\Invoice` or `App\Models\Payment`). This provides an audit trail back to the originating business transaction.

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

### 5. Generating a General Ledger (Account Ledger)

Generate a detailed General Ledger report for a specific account, supporting memory-efficient pagination for large datasets:

```php
use Nml\FinCore\Facades\FinCore;

$ledger = FinCore::getGeneralLedger(
    accountId: 3, // Accounts Receivable
    startDate: '2026-01-01',
    endDate: '2026-06-30',
    sbuCode: 'COLOMBO_SBU',
    perPage: 50,  // Optional pagination limit
    page: 1       // Optional page number
);

foreach ($ledger['accounts'] as $accountReport) {
    $account = $accountReport['account']; // Account model instance
    $openingBalance = $accountReport['opening_balance'];
    $closingBalance = $accountReport['closing_balance'];
    
    // Pagination metadata (only returned if perPage is specified)
    $pagination = $accountReport['pagination'] ?? null;
    
    foreach ($accountReport['entries'] as $entry) {
        $entryNumber = $entry['entry_number'];
        $date = $entry['date'];
        $type = $entry['type']; // 'debit' or 'credit'
        $amount = $entry['amount'];
        $runningBalance = $entry['running_balance'];
    }
}
```

### 6. Generating a Cash Flow Statement

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

### 7. Managing Entry Statuses

Transitions draft entries to submitted, and void or reject them:

```php
// Submit for review
$entry->submit($userId);

// Reject and send back to draft
$entry->returnToDraft('Reason for rejection');

// Void posted journal entry
$entry->void();
```

### 8. Advanced: Tax/VAT & Multi-Currency

#### Registering a Tax Rate
Create a tax rate mapping it to a general ledger account for tax postings:

```php
use Nml\FinCore\Models\Tax;

$vatTax = Tax::create([
    'name' => 'VAT 15%',
    'code' => 'VAT-15',
    'rate' => 15.00,
    'account_id' => 15, // Output VAT Payable account ID
    'is_active' => true
]);
```

#### Creating a Multi-Currency Entry with Automatic Tax Calculation
When creating a foreign currency transaction with tax:
1. Provide the `currency` (e.g. `'USD'`) and `exchange_rate` (e.g. `300.000000`).
2. Provide `fc_amount` (Foreign Currency amount) on lines. The system automatically computes the functional currency `amount = fc_amount * exchange_rate`.
3. Provide `tax_id` and `tax_behavior` (`'inclusive'` or `'exclusive'`). The system calculates the tax and automatically creates the corresponding tax ledger entry.

```php
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Enums\JvType;

$entry = FinCore::createJournalEntry([
    'date' => '2026-06-18',
    'reference' => 'TX-USD-001',
    'type' => JvType::GENERAL->value,
    'description' => 'Foreign sale invoice with tax',
    'currency' => 'USD',
    'exchange_rate' => 300.000000,
    'lines' => [
        [
            'account_id' => 3, // Accounts Receivable (Asset)
            'type' => 'debit',
            'fc_amount' => 115.00, // Total USD including tax
            'description' => 'A/R for foreign sale'
        ],
        [
            'account_id' => 20, // Sales Revenue (Revenue)
            'type' => 'credit',
            'fc_amount' => 115.00,
            'tax_id' => $vatTax->id,
            'tax_behavior' => 'inclusive', // Tax amount (15 USD / 4500 LKR) is extracted and posted to Output VAT
            'description' => 'Sales revenue inclusive of VAT'
        ]
    ]
]);

// Post to ledger. Validates balancing in both USD (115.00) and LKR (34500.00).
$entry->post();
```
