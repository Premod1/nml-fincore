<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');
        $defaultCurrency = config('accounting.currency', 'LKR');

        Schema::table($prefix . 'journal_entries', function (Blueprint $table) use ($defaultCurrency) {
            $table->string('currency', 10)->default($defaultCurrency)->after('status')->index();
            $table->decimal('exchange_rate', 15, 6)->default(1.000000)->after('currency');
        });

        Schema::table($prefix . 'journal_entry_lines', function (Blueprint $table) use ($prefix) {
            $table->decimal('fc_amount', 15, 4)->nullable()->after('amount');
            $table->foreignId('tax_id')->nullable()->after('fc_amount')->constrained($prefix . 'taxes')->onDelete('set null');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::table($prefix . 'journal_entry_lines', function (Blueprint $table) {
            $table->dropForeign(['tax_id']);
            $table->dropColumn(['fc_amount', 'tax_id']);
        });

        Schema::table($prefix . 'journal_entries', function (Blueprint $table) {
            $table->dropColumn(['currency', 'exchange_rate']);
        });
    }
};
