<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::create($prefix . 'accounts', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->string('subtype')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained($prefix . 'accounts')->onDelete('cascade');
            $table->string('currency', 3)->default('BDT');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');
        Schema::dropIfExists($prefix . 'accounts');
    }
};
