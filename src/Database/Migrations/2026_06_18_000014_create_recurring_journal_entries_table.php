<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::create($prefix . 'recurring_journal_entries', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->text('description')->nullable();
            $table->enum('frequency', ['daily', 'weekly', 'monthly', 'quarterly', 'annually', 'custom'])->default('monthly');
            $table->integer('interval')->default(1); // e.g. interval=2 with monthly means every 2 months; or every X days if custom
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('next_run_date')->index();
            $table->timestamp('last_run_at')->nullable();
            $table->integer('max_iterations')->nullable();
            $table->integer('iteration_count')->default(0);
            $table->enum('status', ['active', 'paused', 'completed', 'cancelled'])->default('active')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('auto_post')->default(false); // If true, automatically posts to GL; if false, generates as draft
            $table->string('currency', 10)->default('LKR');
            $table->decimal('exchange_rate', 15, 6)->default(1.000000);
            $table->string('sbu_code')->nullable()->index();
            $table->json('lines_template'); // Array of lines with account_id, type, amount/formula, description
            $table->json('metadata')->nullable(); // Metadata / formula variables (e.g. total_amount)
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');
        Schema::dropIfExists($prefix . 'recurring_journal_entries');
    }
};
