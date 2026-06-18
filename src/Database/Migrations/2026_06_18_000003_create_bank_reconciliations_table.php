<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::create($prefix . 'bank_reconciliations', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('account_id')->constrained($prefix . 'accounts')->onDelete('cascade');
            $table->date('statement_date');
            $table->decimal('opening_balance', 15, 4)->default(0.0000);
            $table->decimal('closing_balance', 15, 4)->default(0.0000);
            $table->timestamp('reconciled_at')->nullable();
            $table->boolean('is_finalized')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');
        Schema::dropIfExists($prefix . 'bank_reconciliations');
    }
};
