<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::table($prefix . 'journal_entry_lines', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('cleared_at');
            $table->string('partnerable_type')->nullable()->after('due_date');
            $table->unsignedBigInteger('partnerable_id')->nullable()->after('partnerable_type');
            
            $table->index(['partnerable_type', 'partnerable_id'], 'fincore_lines_partnerable_idx');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::table($prefix . 'journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex('fincore_lines_partnerable_idx');
            $table->dropColumn(['due_date', 'partnerable_type', 'partnerable_id']);
        });
    }
};
