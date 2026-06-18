<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::create($prefix . 'fixed_assets', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->date('purchase_date');
            $table->decimal('purchase_cost', 15, 2);
            $table->decimal('salvage_value', 15, 2)->default(0.00);
            $table->integer('useful_life_years');
            $table->string('depreciation_method'); // 'straight_line' or 'reducing_balance'
            $table->decimal('depreciation_rate', 5, 2);
            $table->foreignId('asset_account_id')->constrained($prefix . 'accounts')->onDelete('restrict');
            $table->foreignId('accumulated_depreciation_account_id')->constrained($prefix . 'accounts')->onDelete('restrict');
            $table->foreignId('depreciation_expense_account_id')->constrained($prefix . 'accounts')->onDelete('restrict');
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');
        Schema::dropIfExists($prefix . 'fixed_assets');
    }
};
