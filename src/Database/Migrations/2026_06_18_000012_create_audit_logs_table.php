<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::create($prefix . 'audit_logs', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('action');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();

            // Set up foreign key constraint with cascade on delete
            $table->foreign('journal_entry_id')
                ->references('id')
                ->on($prefix . 'journal_entries')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');
        Schema::dropIfExists($prefix . 'audit_logs');
    }
};
