<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        // 1. Composite index for journal entries balance & report filtering (Issue #11)
        Schema::table($prefix . 'journal_entries', function (Blueprint $table) {
            $table->index(['status', 'date', 'sbu_code'], 'fincore_entries_status_date_sbu_idx');
        });

        // 2. Composite indexes for journal entry lines joins & balance sums
        Schema::table($prefix . 'journal_entry_lines', function (Blueprint $table) {
            $table->index(['account_id', 'journal_entry_id'], 'fincore_lines_acc_je_idx');
            $table->index(['account_id', 'type', 'amount'], 'fincore_lines_acc_type_amount_idx');
        });

        // 3. Composite indexes for period locking validation
        Schema::table($prefix . 'fiscal_periods', function (Blueprint $table) {
            $table->index(['start_date', 'end_date', 'is_closed'], 'fincore_periods_date_closed_idx');
        });

        Schema::table($prefix . 'fiscal_years', function (Blueprint $table) {
            $table->index(['start_date', 'end_date', 'is_closed'], 'fincore_years_date_closed_idx');
        });

        // 4. Composite index for depreciation logs monthly lookup
        Schema::table($prefix . 'depreciation_logs', function (Blueprint $table) {
            $table->index(['fixed_asset_id', 'depreciation_date'], 'fincore_depr_asset_date_idx');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::table($prefix . 'depreciation_logs', function (Blueprint $table) {
            $table->dropIndex('fincore_depr_asset_date_idx');
        });

        Schema::table($prefix . 'fiscal_years', function (Blueprint $table) {
            $table->dropIndex('fincore_years_date_closed_idx');
        });

        Schema::table($prefix . 'fiscal_periods', function (Blueprint $table) {
            $table->dropIndex('fincore_periods_date_closed_idx');
        });

        Schema::table($prefix . 'journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex('fincore_lines_acc_type_amount_idx');
            $table->dropIndex('fincore_lines_acc_je_idx');
        });

        Schema::table($prefix . 'journal_entries', function (Blueprint $table) {
            $table->dropIndex('fincore_entries_status_date_sbu_idx');
        });
    }
};
