<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::create($prefix . 'journal_entry_lines', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained($prefix . 'journal_entries')->onDelete('cascade');
            $table->foreignId('account_id')->constrained($prefix . 'accounts')->onDelete('cascade');
            $table->enum('type', ['debit', 'credit']);
            $table->decimal('amount', 15, 4);
            $table->string('description')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->foreignId('bank_reconciliation_id')->nullable()->constrained($prefix . 'bank_reconciliations')->onDelete('set null');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');
        Schema::dropIfExists($prefix . 'journal_entry_lines');
    }
};
