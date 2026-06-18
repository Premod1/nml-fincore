<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::create($prefix . 'journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_number')->unique();
            $table->date('date');
            $table->string('reference')->nullable();
            $table->enum('type', ['general', 'closing', 'adjustment'])->default('general');
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'submitted', 'posted', 'void'])->default('draft');
            $table->string('sbu_code')->nullable();
            $table->nullableMorphs('journalable');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('reviewer_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');
        Schema::dropIfExists($prefix . 'journal_entries');
    }
};
