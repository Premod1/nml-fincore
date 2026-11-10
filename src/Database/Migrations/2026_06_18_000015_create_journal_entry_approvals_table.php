<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::table($prefix . 'journal_entries', function (Blueprint $table) {
            $table->string('status', 30)->default('draft')->change();
            $table->integer('approval_level')->default(0)->after('status');
            $table->text('rejection_reason')->nullable()->after('reviewer_note');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->unsignedBigInteger('rejected_by')->nullable()->after('approved_by');
        });

        Schema::create($prefix . 'journal_entry_approvals', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained($prefix . 'journal_entries')->onDelete('cascade');
            $table->unsignedBigInteger('approver_id');
            $table->integer('level')->default(1);
            $table->enum('action', ['approved', 'rejected']);
            $table->decimal('amount', 15, 4);
            $table->string('role')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['journal_entry_id', 'level']);
            $table->index(['approver_id']);
        });
    }

    public function down(): void
    {
        $prefix = config('accounting.table_prefix', 'fincore_');

        Schema::dropIfExists($prefix . 'journal_entry_approvals');

        Schema::table($prefix . 'journal_entries', function (Blueprint $table) {
            $table->dropColumn(['approval_level', 'rejection_reason', 'rejected_at', 'rejected_by']);
        });
    }
};
