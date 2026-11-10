<?php

namespace Nml\FinCore\Console\Commands;

use Illuminate\Console\Command;
use Nml\FinCore\Models\RecurringJournalEntry;
use Nml\FinCore\Services\RecurringJournalEntryEngine;

class ProcessRecurringJournalEntriesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'fincore:process-recurring
                            {--date= : The reference date to process due entries as of (defaults to today)}
                            {--id= : Process a specific recurring entry by ID}
                            {--force : Force execution even if not currently due}';

    /**
     * The console command description.
     */
    protected $description = 'Process and generate due recurring journal entries';

    /**
     * Execute the console command.
     */
    public function handle(RecurringJournalEntryEngine $engine): int
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');
        $id = $this->option('id');
        $force = (bool) $this->option('force');

        $this->info("Processing recurring journal entries as of {$date}...");

        if ($id) {
            $recurringEntry = RecurringJournalEntry::find($id);
            if (!$recurringEntry) {
                $this->error("Recurring entry with ID {$id} not found.");
                return self::FAILURE;
            }

            $journalEntry = $engine->processEntry($recurringEntry, $date, $force);
            if (!$journalEntry) {
                $this->warn("Recurring entry #{$id} ({$recurringEntry->name}) was not due or could not be processed.");
                return self::SUCCESS;
            }

            $this->info("Successfully generated entry {$journalEntry->entry_number} for '{$recurringEntry->name}' (Status: {$journalEntry->status->value}).");
            return self::SUCCESS;
        }

        $result = $engine->processDue($date);
        $count = $result['processed_count'];

        if ($count === 0) {
            $this->info("No recurring journal entries were due as of {$date}.");
            return self::SUCCESS;
        }

        $this->info("Processed {$count} recurring journal entries:");

        $rows = [];
        foreach ($result['entries'] as $entry) {
            $rows[] = [
                'ID'           => $entry->id,
                'Entry Number' => $entry->entry_number,
                'Date'         => is_string($entry->date) ? $entry->date : $entry->date->format('Y-m-d'),
                'Status'       => $entry->status->value,
                'Reference'    => $entry->reference,
                'Description'  => $entry->description,
                'Amount'       => number_format($entry->getDebitSum(), 2),
            ];
        }

        $this->table(['ID', 'Entry Number', 'Date', 'Status', 'Reference', 'Description', 'Amount'], $rows);

        return self::SUCCESS;
    }
}
