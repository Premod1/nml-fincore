<?php

namespace Nml\FinCore\Services;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Exceptions\AccountingException;
use Nml\FinCore\Models\JournalEntry;
use Nml\FinCore\Models\JournalEntryApproval;

class ApprovalWorkflowEngine
{
    /**
     * Runtime custom role checker callback (Method 1: Hooking host ERP User/Role system).
     * @var Closure|null
     */
    protected static ?Closure $customRoleChecker = null;

    /**
     * Register a custom role checker callback.
     * Signature: function (int|string $userId, string $requiredRole): bool
     */
    public static function setRoleChecker(?Closure $checker): void
    {
        static::$customRoleChecker = $checker;
    }

    /**
     * Check if approval workflow is enabled in configuration.
     */
    public function isWorkflowEnabled(): bool
    {
        return (bool) config('accounting.approval_workflow.enabled', false);
    }

    /**
     * Check if strict maker-checker is enforced.
     */
    public function isMakerCheckerEnforced(): bool
    {
        return (bool) config('accounting.approval_workflow.enforce_maker_checker', true);
    }

    /**
     * Get configured approval threshold tiers.
     */
    public function getThresholds(): array
    {
        return config('accounting.approval_workflow.thresholds', [
            [
                'level'      => 1,
                'max_amount' => 50000.00,
                'role'       => 'accountant',
            ],
            [
                'level'      => 2,
                'max_amount' => 500000.00,
                'role'       => 'finance_manager',
            ],
            [
                'level'      => 3,
                'max_amount' => null,
                'role'       => 'cfo',
            ],
        ]);
    }

    /**
     * Determine the required threshold tier for a given monetary amount.
     */
    public function getRequiredTierForAmount(float $amount): array
    {
        $tiers = $this->getThresholds();
        usort($tiers, fn($a, $b) => ($a['level'] ?? 0) <=> ($b['level'] ?? 0));

        foreach ($tiers as $tier) {
            if ($tier['max_amount'] === null || $amount <= (float) $tier['max_amount']) {
                return $tier;
            }
        }

        return end($tiers) ?: [
            'level'      => 1,
            'max_amount' => null,
            'role'       => 'accountant',
        ];
    }

    /**
     * Check if a user possesses the required role using Method 1 (Resolver / Callback).
     */
    public function checkUserHasRole(int|string $userId, string $requiredRole): bool
    {
        // 1. Check static callback set via FinCore::setRoleChecker()
        if (static::$customRoleChecker !== null) {
            return (bool) call_user_func(static::$customRoleChecker, $userId, $requiredRole);
        }

        // 2. Check callback defined in config('accounting.approval_workflow.role_checker')
        $configChecker = config('accounting.approval_workflow.role_checker');
        if ($configChecker instanceof Closure) {
            return (bool) $configChecker($userId, $requiredRole);
        }

        // 3. Fallback: inspect host application's User model if available
        $userModel = config('auth.providers.users.model', 'App\Models\User');
        if (class_exists($userModel)) {
            $user = $userModel::find($userId);
            if ($user) {
                // Spatie / custom RBAC method hasRole()
                if (method_exists($user, 'hasRole')) {
                    return (bool) $user->hasRole($requiredRole);
                }
                // Custom property $user->role
                if (isset($user->role)) {
                    return (string) $user->role === $requiredRole;
                }
                // Laravel Gate / Policy can()
                if (method_exists($user, 'can') && $user->can($requiredRole)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check whether a specific user is authorized to approve a given journal entry.
     */
    public function canApprove(JournalEntry $entry, int|string $userId, ?string $role = null): bool
    {
        // Status must be pending approval or submitted
        if (!in_array($entry->status, [JvStatus::PENDING_APPROVAL, JvStatus::SUBMITTED])) {
            return false;
        }

        // 1. Strict Maker-Checker enforcement: Creator/submitter cannot approve own entry
        if ($this->isMakerCheckerEnforced()) {
            if ($entry->created_by !== null && (string) $entry->created_by === (string) $userId) {
                return false;
            }
            if ($entry->submitted_by !== null && (string) $entry->submitted_by === (string) $userId) {
                return false;
            }
        }

        // 2. Threshold & Role hierarchy check
        $amount = $entry->getDebitSum();
        $requiredTier = $this->getRequiredTierForAmount($amount);
        $requiredLevel = $requiredTier['level'];

        // Collect all roles that meet or exceed the required tier level
        $eligibleRoles = [];
        foreach ($this->getThresholds() as $tier) {
            if ($tier['level'] >= $requiredLevel) {
                $eligibleRoles[] = $tier['role'];
            }
        }

        // If caller passed role explicitly
        if ($role !== null && in_array($role, $eligibleRoles, true)) {
            return true;
        }

        // Check user against eligible roles
        foreach ($eligibleRoles as $eligibleRole) {
            if ($this->checkUserHasRole($userId, $eligibleRole)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Submit a draft or rejected journal entry for approval.
     */
    public function submitForApproval(JournalEntry|int $entry, ?int $userId = null): JournalEntry
    {
        return DB::transaction(function () use ($entry, $userId) {
            if (is_numeric($entry)) {
                $entry = JournalEntry::findOrFail($entry);
            }

            /** @var JournalEntry $entry */
            $entry = JournalEntry::where('id', $entry->id)->lockForUpdate()->first();

            if (!in_array($entry->status, [JvStatus::DRAFT, JvStatus::REJECTED])) {
                throw new AccountingException("Only draft or rejected entries can be submitted for approval. Current status: {$entry->status->value}.");
            }

            // Verify double-entry balance
            $tolerance = (float) config('accounting.rounding_tolerance', 0.005);
            $diff = abs($entry->getDebitSum() - $entry->getCreditSum());
            if ($diff > $tolerance) {
                throw new AccountingException("Cannot submit unbalanced journal entry. Debits and Credits difference is {$diff}.");
            }

            $entry->update([
                'status'           => JvStatus::PENDING_APPROVAL,
                'submitted_by'     => $userId ?? auth()->id(),
                'submitted_at'     => now(),
                'approval_level'   => 0,
                'rejection_reason' => null,
            ]);

            $entry->syncOriginal();

            return $entry;
        });
    }

    /**
     * Approve a journal entry adhering to maker-checker and threshold rules.
     */
    public function approve(
        JournalEntry|int $entry,
        int|string $approverId,
        ?string $comment = null,
        ?string $role = null
    ): JournalEntry {
        return DB::transaction(function () use ($entry, $approverId, $comment, $role) {
            if (is_numeric($entry)) {
                $entry = JournalEntry::findOrFail($entry);
            }

            /** @var JournalEntry $entry */
            $entry = JournalEntry::where('id', $entry->id)->lockForUpdate()->first();

            if (!in_array($entry->status, [JvStatus::PENDING_APPROVAL, JvStatus::SUBMITTED])) {
                throw new AccountingException("Entry must be in 'pending_approval' or 'submitted' status to be approved. Current status: {$entry->status->value}.");
            }

            // 1. Strict Maker-Checker check
            if ($this->isMakerCheckerEnforced()) {
                if ($entry->created_by !== null && (string) $entry->created_by === (string) $approverId) {
                    throw new AccountingException("Maker-checker violation: User {$approverId} cannot approve an entry they created.");
                }
                if ($entry->submitted_by !== null && (string) $entry->submitted_by === (string) $approverId) {
                    throw new AccountingException("Maker-checker violation: User {$approverId} cannot approve an entry they submitted.");
                }
            }

            // 2. Threshold authorization check
            $amount = $entry->getDebitSum();
            $requiredTier = $this->getRequiredTierForAmount($amount);

            if (!$this->canApprove($entry, $approverId, $role)) {
                throw new AccountingException(
                    "User {$approverId} is not authorized to approve entry #{$entry->entry_number} of amount {$amount}. Required role level: {$requiredTier['level']} ({$requiredTier['role']})."
                );
            }

            // 3. Log approval history record
            JournalEntryApproval::create([
                'journal_entry_id' => $entry->id,
                'approver_id'      => (int) $approverId,
                'level'            => $requiredTier['level'],
                'action'           => 'approved',
                'amount'           => $amount,
                'role'             => $role ?: $requiredTier['role'],
                'comment'          => $comment,
            ]);

            // 4. Update journal entry state to APPROVED
            $entry->update([
                'status'         => JvStatus::APPROVED,
                'approved_by'    => (int) $approverId,
                'approved_at'    => now(),
                'reviewer_note'  => $comment,
                'approval_level' => $requiredTier['level'],
            ]);

            $entry->syncOriginal();

            return $entry;
        });
    }

    /**
     * Reject a journal entry with a mandatory rejection reason.
     */
    public function reject(
        JournalEntry|int $entry,
        int|string $approverId,
        string $reason,
        ?string $role = null
    ): JournalEntry {
        return DB::transaction(function () use ($entry, $approverId, $reason, $role) {
            if (is_numeric($entry)) {
                $entry = JournalEntry::findOrFail($entry);
            }

            /** @var JournalEntry $entry */
            $entry = JournalEntry::where('id', $entry->id)->lockForUpdate()->first();

            if (!in_array($entry->status, [JvStatus::PENDING_APPROVAL, JvStatus::SUBMITTED])) {
                throw new AccountingException("Entry must be in 'pending_approval' or 'submitted' status to be rejected. Current status: {$entry->status->value}.");
            }

            $reason = trim($reason);
            if (empty($reason)) {
                throw new AccountingException("A rejection reason is required when rejecting a journal entry.");
            }

            // Log rejection record in approvals table
            JournalEntryApproval::create([
                'journal_entry_id' => $entry->id,
                'approver_id'      => (int) $approverId,
                'level'            => $entry->approval_level ?: 1,
                'action'           => 'rejected',
                'amount'           => $entry->getDebitSum(),
                'role'             => $role,
                'comment'          => $reason,
            ]);

            $entry->update([
                'status'           => JvStatus::REJECTED,
                'rejected_by'      => (int) $approverId,
                'rejected_at'      => now(),
                'rejection_reason' => $reason,
                'reviewer_note'    => $reason,
            ]);

            $entry->syncOriginal();

            return $entry;
        });
    }

    /**
     * Fetch pending approval entries, optionally filtered for a specific approver.
     */
    public function getPendingApprovals(
        int|string|null $userId = null,
        ?string $role = null
    ): Collection {
        $query = JournalEntry::whereIn('status', [JvStatus::PENDING_APPROVAL, JvStatus::SUBMITTED])
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc');

        $entries = $query->get();

        if ($userId === null && $role === null) {
            return $entries;
        }

        return $entries->filter(fn(JournalEntry $entry) => $this->canApprove($entry, $userId, $role))->values();
    }

    /**
     * Retrieve the complete approval and rejection history for a journal entry.
     */
    public function getApprovalHistory(JournalEntry|int $entry): Collection
    {
        $id = is_numeric($entry) ? $entry : $entry->id;

        return JournalEntryApproval::where('journal_entry_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();
    }
}
