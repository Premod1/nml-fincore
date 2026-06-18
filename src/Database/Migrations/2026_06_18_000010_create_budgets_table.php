<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::create($prefix . 'budgets', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('account_id')->constrained($prefix . 'accounts')->onDelete('cascade');
            $table->string('sbu_code')->nullable()->index();
            $table->integer('fiscal_year')->index();
            $table->integer('month')->index();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->unique(['account_id', 'sbu_code', 'fiscal_year', 'month'], 'fincore_budgets_unique_idx');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');
        Schema::dropIfExists($prefix . 'budgets');
    }
};
