<?php

namespace Nml\FinCore\Services;

use DateTime;
use Illuminate\Support\Facades\DB;
use Nml\FinCore\Enums\JvType;
use Nml\FinCore\Exceptions\AccountingException;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\JournalEntry;
use Nml\FinCore\Models\RecurringJournalEntry;

class RecurringJournalEntryEngine
{
    /**
     * Create a new recurring journal entry template.
     */
    public function create(array $data): RecurringJournalEntry
    {
        if (empty($data['name'])) {
            throw new AccountingException("Recurring entry name is required.");
        }

        if (empty($data['start_date'])) {
            throw new AccountingException("Recurring entry start_date is required.");
        }

        if (empty($data['lines_template']) || !is_array($data['lines_template'])) {
            throw new AccountingException("Recurring entry lines_template must be a non-empty array.");
        }

        $frequency = $data['frequency'] ?? 'monthly';
        $allowedFrequencies = ['daily', 'weekly', 'monthly', 'quarterly', 'annually', 'custom'];
        if (!in_array($frequency, $allowedFrequencies)) {
            throw new AccountingException("Invalid frequency '{$frequency}'. Allowed: " . implode(', ', $allowedFrequencies));
        }

        $interval = (int) ($data['interval'] ?? 1);
        if ($interval < 1) {
            throw new AccountingException("Interval must be at least 1.");
        }

        $startDate = $data['start_date'];
        $nextRunDate = $data['next_run_date'] ?? $startDate;

        return RecurringJournalEntry::create([
            'name'            => $data['name'],
            'code'            => $data['code'] ?? null,
            'description'     => $data['description'] ?? null,
            'frequency'       => $frequency,
            'interval'        => $interval,
            'start_date'      => $startDate,
            'end_date'        => $data['end_date'] ?? null,
            'next_run_date'   => $nextRunDate,
            'max_iterations'  => isset($data['max_iterations']) ? (int) $data['max_iterations'] : null,
            'iteration_count' => (int) ($data['iteration_count'] ?? 0),
            'status'          => $data['status'] ?? 'active',
            'is_active'       => $data['is_active'] ?? true,
            'auto_post'       => $data['auto_post'] ?? false,
            'currency'        => $data['currency'] ?? config('accounting.currency', 'LKR'),
            'exchange_rate'   => (float) ($data['exchange_rate'] ?? 1.0),
            'sbu_code'        => $data['sbu_code'] ?? null,
            'lines_template'  => $data['lines_template'],
            'metadata'        => $data['metadata'] ?? [],
            'created_by'      => $data['created_by'] ?? null,
        ]);
    }

    /**
     * Update an existing recurring journal entry template.
     */
    public function update(RecurringJournalEntry|int $entry, array $data): RecurringJournalEntry
    {
        if (is_numeric($entry)) {
            $entry = RecurringJournalEntry::findOrFail($entry);
        }

        if (isset($data['frequency'])) {
            $allowedFrequencies = ['daily', 'weekly', 'monthly', 'quarterly', 'annually', 'custom'];
            if (!in_array($data['frequency'], $allowedFrequencies)) {
                throw new AccountingException("Invalid frequency '{$data['frequency']}'.");
            }
        }

        if (isset($data['interval']) && (int) $data['interval'] < 1) {
            throw new AccountingException("Interval must be at least 1.");
        }

        $entry->update($data);

        return $entry->fresh();
    }

    /**
     * Process due recurring journal entries as of a given date.
     *
     * @param string|null $asOfDate
     * @param int|null $userId
     * @return array
     */
    public function processDue(?string $asOfDate = null, ?int $userId = null): array
    {
        $date = $asOfDate ?: now()->format('Y-m-d');
        $dueTemplates = RecurringJournalEntry::due($date)->orderBy('id')->get();

        $processedEntries = [];

        foreach ($dueTemplates as $template) {
            $entry = $this->processEntry($template, $date, force: false, userId: $userId);
            if ($entry) {
                $processedEntries[] = $entry;
            }
        }

        return [
            'date'            => $date,
            'processed_count' => count($processedEntries),
            'entries'         => $processedEntries,
        ];
    }

    /**
     * Process a single recurring journal entry and generate its JournalEntry.
     */
    public function processEntry(
        RecurringJournalEntry|int $recurringEntry,
        ?string $runDate = null,
        bool $force = false,
        ?int $userId = null
    ): ?JournalEntry {
        return DB::transaction(function () use ($recurringEntry, $runDate, $force, $userId) {
            if (is_numeric($recurringEntry)) {
                $recurringEntry = RecurringJournalEntry::findOrFail($recurringEntry);
            }

            /** @var RecurringJournalEntry $recurringEntry */
            $recurringEntry = RecurringJournalEntry::where('id', $recurringEntry->id)
                ->lockForUpdate()
                ->first();

            $date = $runDate ?: ($recurringEntry->next_run_date ? $recurringEntry->next_run_date->format('Y-m-d') : now()->format('Y-m-d'));

            if (!$force) {
                if (!$recurringEntry->is_active || $recurringEntry->status !== 'active') {
                    return null;
                }

                if ($recurringEntry->start_date && $recurringEntry->start_date->format('Y-m-d') > $date) {
                    return null;
                }

                if ($recurringEntry->isExpired($date)) {
                    $recurringEntry->update(['status' => 'completed', 'is_active' => false]);
                    return null;
                }

                if ($recurringEntry->hasReachedMaxIterations()) {
                    $recurringEntry->update(['status' => 'completed', 'is_active' => false]);
                    return null;
                }

                if ($recurringEntry->next_run_date && $recurringEntry->next_run_date->format('Y-m-d') > $date) {
                    return null;
                }
            }

            $currentIteration = $recurringEntry->iteration_count + 1;

            $context = array_merge($recurringEntry->metadata ?: [], [
                'iteration'            => $currentIteration,
                'max_iterations'       => $recurringEntry->max_iterations ?? 1,
                'remaining_iterations' => $recurringEntry->max_iterations ? max($recurringEntry->max_iterations - $currentIteration + 1, 0) : null,
                'date'                 => $date,
                'days_in_month'        => (int) date('t', strtotime($date)),
                'month'                => (int) date('m', strtotime($date)),
                'month_name'           => date('F', strtotime($date)),
                'year'                 => (int) date('Y', strtotime($date)),
            ]);

            // Process lines template with dynamic formula / variable evaluation
            $processedLines = [];
            foreach ($recurringEntry->lines_template as $lineTpl) {
                $amount = 0.0;

                if (!empty($lineTpl['amount_formula'])) {
                    $amount = $this->evaluateFormula($lineTpl['amount_formula'], $context);
                } elseif (isset($lineTpl['amount'])) {
                    $amount = (float) $lineTpl['amount'];
                }

                $fcAmount = null;
                if (!empty($lineTpl['fc_amount_formula'])) {
                    $fcAmount = $this->evaluateFormula($lineTpl['fc_amount_formula'], $context);
                } elseif (isset($lineTpl['fc_amount'])) {
                    $fcAmount = (float) $lineTpl['fc_amount'];
                }

                $lineDesc = !empty($lineTpl['description'])
                    ? $this->interpolateTemplateString($lineTpl['description'], $context)
                    : null;

                $processedLines[] = [
                    'account_id'       => $lineTpl['account_id'],
                    'type'             => $lineTpl['type'],
                    'amount'           => $amount,
                    'fc_amount'        => $fcAmount,
                    'tax_id'           => $lineTpl['tax_id'] ?? null,
                    'tax_behavior'     => $lineTpl['tax_behavior'] ?? 'exclusive',
                    'description'      => $lineDesc,
                    'partnerable_type' => $lineTpl['partnerable_type'] ?? null,
                    'partnerable_id'   => $lineTpl['partnerable_id'] ?? null,
                ];
            }

            // Reference and description
            if (!empty($recurringEntry->code)) {
                $ref = "{$recurringEntry->code}-" . str_pad((string) $currentIteration, 3, '0', STR_PAD_LEFT);
            } else {
                $ref = "REC-{$recurringEntry->id}-" . date('Ymd', strtotime($date));
            }

            $entryDesc = $this->interpolateTemplateString(
                $recurringEntry->description ?: $recurringEntry->name,
                $context
            );

            // Create the JournalEntry
            $ledgerEngine = new LedgerEngine();
            $journalEntry = $ledgerEngine->createJournalEntry([
                'date'             => $date,
                'reference'        => $ref,
                'type'             => JvType::GENERAL->value,
                'description'      => $entryDesc,
                'currency'         => $recurringEntry->currency,
                'exchange_rate'    => $recurringEntry->exchange_rate,
                'sbu_code'         => $recurringEntry->sbu_code,
                'journalable_type' => RecurringJournalEntry::class,
                'journalable_id'   => $recurringEntry->id,
                'created_by'       => $userId ?? $recurringEntry->created_by,
                'lines'            => $processedLines,
            ]);

            // Auto-post if requested
            if ($recurringEntry->auto_post) {
                $journalEntry->post(bypassPeriodLock: false, userId: $userId);
            }

            // Calculate next schedule
            $nextRunDate = $this->calculateNextRunDate(
                $date,
                $recurringEntry->frequency,
                $recurringEntry->interval
            );

            $isCompleted = false;
            if ($recurringEntry->max_iterations !== null && $currentIteration >= $recurringEntry->max_iterations) {
                $isCompleted = true;
            } elseif ($recurringEntry->end_date && $nextRunDate > $recurringEntry->end_date->format('Y-m-d')) {
                $isCompleted = true;
            }

            $recurringEntry->update([
                'iteration_count' => $currentIteration,
                'last_run_at'     => now(),
                'next_run_date'   => $nextRunDate,
                'status'          => $isCompleted ? 'completed' : 'active',
                'is_active'       => !$isCompleted,
            ]);

            return $journalEntry;
        });
    }

    /**
     * Compute next schedule date based on frequency and interval.
     */
    public function calculateNextRunDate(string $currentDate, string $frequency, int $interval = 1): string
    {
        $interval = max($interval, 1);
        $dt = new DateTime($currentDate);

        switch ($frequency) {
            case 'daily':
                $dt->modify("+{$interval} day");
                return $dt->format('Y-m-d');

            case 'weekly':
                $dt->modify("+{$interval} week");
                return $dt->format('Y-m-d');

            case 'monthly':
                $day = (int) $dt->format('d');
                $dt->modify("first day of +{$interval} month");
                $daysInMonth = (int) $dt->format('t');
                $dt->setDate((int) $dt->format('Y'), (int) $dt->format('m'), min($day, $daysInMonth));
                return $dt->format('Y-m-d');

            case 'quarterly':
                $months = $interval * 3;
                $day = (int) $dt->format('d');
                $dt->modify("first day of +{$months} month");
                $daysInMonth = (int) $dt->format('t');
                $dt->setDate((int) $dt->format('Y'), (int) $dt->format('m'), min($day, $daysInMonth));
                return $dt->format('Y-m-d');

            case 'annually':
                $day = (int) $dt->format('d');
                $month = (int) $dt->format('m');
                $year = ((int) $dt->format('Y')) + $interval;
                $daysInMonth = (int) (new DateTime("{$year}-{$month}-01"))->format('t');
                $dt->setDate($year, $month, min($day, $daysInMonth));
                return $dt->format('Y-m-d');

            case 'custom':
            default:
                $dt->modify("+{$interval} day");
                return $dt->format('Y-m-d');
        }
    }

    /**
     * Evaluate a formula or variable expression into a float value.
     */
    public function evaluateFormula(string|float|int $amountOrFormula, array $context): float
    {
        if (is_numeric($amountOrFormula)) {
            return (float) $amountOrFormula;
        }

        $formula = (string) $amountOrFormula;

        // 1. Resolve dynamic balance function calls: balance('1000') or balance(1)
        $formula = preg_replace_callback('/balance\(\s*[\'"]?([A-Za-z0-9_-]+)[\'"]?\s*\)/i', function ($matches) {
            $target = $matches[1];
            $account = Account::where('code', $target)->first();
            if (!$account && is_numeric($target)) {
                $account = Account::find($target);
            }

            return $account ? (string) $account->getCurrentBalance() : '0';
        }, $formula);

        // 2. Substitute context variables (e.g. total_amount, iteration, max_iterations)
        foreach ($context as $key => $val) {
            if (is_scalar($val) && is_numeric($val)) {
                $formula = preg_replace('/\b' . preg_quote((string) $key, '/') . '\b/i', (string) $val, $formula);
            }
        }

        // 3. Evaluate mathematical expression using safe Shunting-yard algorithm
        return $this->evaluateMathExpression($formula);
    }

    /**
     * Interpolate template strings with context variables like {iteration}, {month_name}, {year}.
     */
    public function interpolateTemplateString(string $template, array $context): string
    {
        return preg_replace_callback('/\{([A-Za-z0-9_]+)\}/', function ($matches) use ($context) {
            $key = $matches[1];
            if (array_key_exists($key, $context)) {
                return (string) $context[$key];
            }
            return $matches[0];
        }, $template);
    }

    /**
     * Safe mathematical expression evaluator without eval().
     */
    protected function evaluateMathExpression(string $expr): float
    {
        $expr = preg_replace('/\s+/', '', $expr);
        if ($expr === '' || $expr === null) {
            return 0.0;
        }

        $tokens = [];
        $length = strlen($expr);
        $i = 0;

        while ($i < $length) {
            $ch = $expr[$i];

            if (is_numeric($ch) || $ch === '.') {
                $num = '';
                while ($i < $length && (is_numeric($expr[$i]) || $expr[$i] === '.')) {
                    $num .= $expr[$i];
                    $i++;
                }
                $tokens[] = (float) $num;
                continue;
            }

            if (in_array($ch, ['+', '-', '*', '/', '(', ')'])) {
                // Handle unary minus
                if ($ch === '-' && (empty($tokens) || in_array(end($tokens), ['+', '-', '*', '/', '(']))) {
                    $i++;
                    $num = '-';
                    while ($i < $length && (is_numeric($expr[$i]) || $expr[$i] === '.')) {
                        $num .= $expr[$i];
                        $i++;
                    }
                    $tokens[] = (float) $num;
                    continue;
                }

                $tokens[] = $ch;
                $i++;
                continue;
            }

            throw new AccountingException("Invalid token '{$ch}' in recurring formula expression.");
        }

        // Shunting-yard algorithm
        $output = [];
        $ops = [];
        $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];

        foreach ($tokens as $token) {
            if (is_numeric($token)) {
                $output[] = (float) $token;
            } elseif ($token === '(') {
                $ops[] = $token;
            } elseif ($token === ')') {
                while (!empty($ops) && end($ops) !== '(') {
                    $output[] = array_pop($ops);
                }
                if (empty($ops)) {
                    throw new AccountingException("Mismatched parentheses in recurring formula.");
                }
                array_pop($ops);
            } else {
                while (!empty($ops) && end($ops) !== '(' && ($precedence[end($ops)] ?? 0) >= $precedence[$token]) {
                    $output[] = array_pop($ops);
                }
                $ops[] = $token;
            }
        }

        while (!empty($ops)) {
            $op = array_pop($ops);
            if ($op === '(' || $op === ')') {
                throw new AccountingException("Mismatched parentheses in recurring formula.");
            }
            $output[] = $op;
        }

        // Evaluate RPN
        $stack = [];
        foreach ($output as $token) {
            if (is_numeric($token)) {
                $stack[] = (float) $token;
            } else {
                if (count($stack) < 2) {
                    throw new AccountingException("Malformed formula expression in recurring entry.");
                }
                $b = array_pop($stack);
                $a = array_pop($stack);

                switch ($token) {
                    case '+': $stack[] = $a + $b; break;
                    case '-': $stack[] = $a - $b; break;
                    case '*': $stack[] = $a * $b; break;
                    case '/':
                        if (abs($b) < 1e-12) {
                            throw new AccountingException("Division by zero in recurring formula.");
                        }
                        $stack[] = $a / $b;
                        break;
                }
            }
        }

        if (count($stack) !== 1) {
            throw new AccountingException("Could not evaluate formula expression in recurring entry.");
        }

        return round($stack[0], 4);
    }
}
