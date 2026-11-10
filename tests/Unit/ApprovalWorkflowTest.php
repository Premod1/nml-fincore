<?php

namespace Nml\FinCore\Tests\Unit;

use Nml\FinCore\Enums\AccountType;
use Nml\FinCore\Enums\JvStatus;
use Nml\FinCore\Exceptions\AccountingException;
use Nml\FinCore\Facades\FinCore;
use Nml\FinCore\Models\Account;
use Nml\FinCore\Models\JournalEntry;
use Nml\FinCore\Services\ApprovalWorkflowEngine;
use Nml\FinCore\Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    private Account $cashAccount;
    private Account $revenueAccount;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable approval workflow for this test suite
        config(['accounting.approval_workflow.enabled' => true]);
        config(['accounting.approval_workflow.enforce_maker_checker' => true]);

        $this->cashAccount = Account::create([
            'code'      => '1000',
            'name'      => 'Cash',
            'type'      => AccountType::ASSET,
            'subtype'   => 'current_asset',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);

        $this->revenueAccount = Account::create([
            'code'      => '4000',
            'name'      => 'Revenue',
            'type'      => AccountType::REVENUE,
            'subtype'   => 'revenue',
            'currency'  => 'LKR',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Reset custom role checker callback
        ApprovalWorkflowEngine::setRoleChecker(null);
        parent::tearDown();
    }

    public function test_entry_lifecycle_from_draft_to_posted(): void
    {
        // Set up Method 1: ERP role resolver callback
        FinCore::setRoleChecker(function ($userId, $role) {
            // User 100 is accountant
            return $userId == 100 && $role === 'accountant';
        });

        // 1. Create entry by Maker (User 1)
        $entry = FinCore::createJournalEntry([
            'date'        => '2026-06-01',
            'description' => 'Test Sales Entry',
            'created_by'  => 1,
            'lines'       => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 15000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 15000.0],
            ],
        ]);

        $this->assertEquals(JvStatus::DRAFT, $entry->status);

        // 2. Submit for approval by User 1
        $entry->submitForApproval(userId: 1);
        $entry->refresh();
        $this->assertEquals(JvStatus::PENDING_APPROVAL, $entry->status);
        $this->assertEquals(1, $entry->submitted_by);
        $this->assertNotNull($entry->submitted_at);

        // Cannot post directly while in pending_approval
        try {
            $entry->post();
            $this->fail("Expected AccountingException was not thrown when posting unapproved entry.");
        } catch (AccountingException $e) {
            $this->assertStringContainsStringIgnoringCase("entry must be approved first", $e->getMessage());
        }

        // 3. Approve by Checker (User 100 - Accountant)
        $this->assertTrue(FinCore::canApproveJournalEntry($entry, userId: 100));
        FinCore::approveJournalEntry($entry, approverId: 100, comment: 'Verified and approved');

        $entry->refresh();
        $this->assertEquals(JvStatus::APPROVED, $entry->status);
        $this->assertEquals(100, $entry->approved_by);
        $this->assertEquals('Verified and approved', $entry->reviewer_note);

        // 4. Post approved entry to General Ledger
        $entry->post();
        $this->assertEquals(JvStatus::POSTED, $entry->fresh()->status);
        $this->assertEquals(15000.0, $this->cashAccount->getCurrentBalance());
    }

    public function test_strict_maker_checker_prevents_creator_from_approving(): void
    {
        // User 50 is both creator and CFO
        FinCore::setRoleChecker(fn($userId, $role) => true);

        $entry = FinCore::createJournalEntry([
            'date'       => '2026-06-01',
            'created_by' => 50,
            'lines'      => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 10000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 10000.0],
            ],
        ]);

        $entry->submitForApproval(userId: 50);

        // canApprove must return false for maker
        $this->assertFalse(FinCore::canApproveJournalEntry($entry, userId: 50));

        // Attempting to approve throws exception
        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage("Maker-checker violation: User 50 cannot approve an entry they created");

        FinCore::approveJournalEntry($entry, approverId: 50);
    }

    public function test_strict_maker_checker_prevents_submitter_from_approving(): void
    {
        FinCore::setRoleChecker(fn($userId, $role) => true);

        $entry = FinCore::createJournalEntry([
            'date'       => '2026-06-01',
            'created_by' => 10,
            'lines'      => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 10000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 10000.0],
            ],
        ]);

        // User 20 submits
        $entry->submitForApproval(userId: 20);

        $this->assertFalse(FinCore::canApproveJournalEntry($entry, userId: 20));

        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage("Maker-checker violation: User 20 cannot approve an entry they submitted");

        FinCore::approveJournalEntry($entry, approverId: 20);
    }

    public function test_threshold_tier_enforcement(): void
    {
        // User 101 = accountant (up to 50k)
        // User 102 = finance_manager (up to 500k)
        // User 103 = cfo (unlimited)
        FinCore::setRoleChecker(function ($userId, $role) {
            if ($userId == 101 && $role === 'accountant') return true;
            if ($userId == 102 && $role === 'finance_manager') return true;
            if ($userId == 103 && $role === 'cfo') return true;
            return false;
        });

        // 1. Tier 1 entry: 30,000 LKR (Requires Accountant, Finance Manager, or CFO)
        $tier1Entry = FinCore::createJournalEntry([
            'date'       => '2026-06-01',
            'created_by' => 1,
            'lines'      => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 30000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 30000.0],
            ],
        ]);
        $tier1Entry->submitForApproval(1);

        $this->assertTrue(FinCore::canApproveJournalEntry($tier1Entry, userId: 101)); // Accountant can approve
        $this->assertTrue(FinCore::canApproveJournalEntry($tier1Entry, userId: 102)); // Manager can approve
        $this->assertTrue(FinCore::canApproveJournalEntry($tier1Entry, userId: 103)); // CFO can approve

        // 2. Tier 2 entry: 250,000 LKR (Requires Finance Manager or CFO, Accountant NOT allowed)
        $tier2Entry = FinCore::createJournalEntry([
            'date'       => '2026-06-01',
            'created_by' => 1,
            'lines'      => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 250000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 250000.0],
            ],
        ]);
        $tier2Entry->submitForApproval(1);

        $this->assertFalse(FinCore::canApproveJournalEntry($tier2Entry, userId: 101)); // Accountant CANNOT approve
        $this->assertTrue(FinCore::canApproveJournalEntry($tier2Entry, userId: 102));  // Finance Manager CAN approve
        $this->assertTrue(FinCore::canApproveJournalEntry($tier2Entry, userId: 103));  // CFO CAN approve

        // 3. Tier 3 entry: 1,500,000 LKR (Requires CFO only)
        $tier3Entry = FinCore::createJournalEntry([
            'date'       => '2026-06-01',
            'created_by' => 1,
            'lines'      => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 1500000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 1500000.0],
            ],
        ]);
        $tier3Entry->submitForApproval(1);

        $this->assertFalse(FinCore::canApproveJournalEntry($tier3Entry, userId: 101)); // Accountant CANNOT approve
        $this->assertFalse(FinCore::canApproveJournalEntry($tier3Entry, userId: 102)); // Manager CANNOT approve
        $this->assertTrue(FinCore::canApproveJournalEntry($tier3Entry, userId: 103));  // CFO CAN approve
    }

    public function test_rejection_requires_reason_and_allows_revision(): void
    {
        FinCore::setRoleChecker(fn($userId, $role) => true);

        $entry = FinCore::createJournalEntry([
            'date'       => '2026-06-01',
            'created_by' => 1,
            'lines'      => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 10000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 10000.0],
            ],
        ]);
        $entry->submitForApproval(1);

        // Rejecting without reason fails
        try {
            FinCore::rejectJournalEntry($entry, approverId: 99, reason: '');
            $this->fail("Expected exception for empty rejection reason was not thrown.");
        } catch (AccountingException $e) {
            $this->assertStringContainsString("rejection reason is required", $e->getMessage());
        }

        // Successfully reject with reason
        FinCore::rejectJournalEntry($entry, approverId: 99, reason: 'Incorrect revenue account coding');
        $entry->refresh();

        $this->assertEquals(JvStatus::REJECTED, $entry->status);
        $this->assertEquals(99, $entry->rejected_by);
        $this->assertEquals('Incorrect revenue account coding', $entry->rejection_reason);
        $this->assertNotNull($entry->rejected_at);

        // Maker revises entry and returns to draft
        $entry->returnToDraft('Fixing account coding');
        $entry->refresh();
        $this->assertEquals(JvStatus::DRAFT, $entry->status);

        // Resubmit for approval
        $entry->submitForApproval(userId: 1);
        $this->assertEquals(JvStatus::PENDING_APPROVAL, $entry->fresh()->status);
    }

    public function test_pending_approvals_filter_by_authorized_user(): void
    {
        FinCore::setRoleChecker(function ($userId, $role) {
            if ($userId == 101 && $role === 'accountant') return true;
            if ($userId == 103 && $role === 'cfo') return true;
            return false;
        });

        // Small entry (30k)
        $small = FinCore::createJournalEntry([
            'date'       => '2026-06-01',
            'created_by' => 1,
            'lines'      => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 30000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 30000.0],
            ],
        ]);
        $small->submitForApproval(1);

        // Large entry (1M)
        $large = FinCore::createJournalEntry([
            'date'       => '2026-06-01',
            'created_by' => 1,
            'lines'      => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 1000000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 1000000.0],
            ],
        ]);
        $large->submitForApproval(1);

        // Accountant (101) only sees the small entry
        $forAccountant = FinCore::getPendingApprovals(userId: 101);
        $this->assertCount(1, $forAccountant);
        $this->assertEquals($small->id, $forAccountant->first()->id);

        // CFO (103) sees both entries
        $forCfo = FinCore::getPendingApprovals(userId: 103);
        $this->assertCount(2, $forCfo);

        // Maker (1) sees 0 because maker-checker prevents approving own entries
        $forMaker = FinCore::getPendingApprovals(userId: 1);
        $this->assertCount(0, $forMaker);
    }

    public function test_approval_history_audit_trail(): void
    {
        FinCore::setRoleChecker(fn($userId, $role) => true);

        $entry = FinCore::createJournalEntry([
            'date'       => '2026-06-01',
            'created_by' => 1,
            'lines'      => [
                ['account_id' => $this->cashAccount->id, 'type' => 'debit', 'amount' => 20000.0],
                ['account_id' => $this->revenueAccount->id, 'type' => 'credit', 'amount' => 20000.0],
            ],
        ]);
        $entry->submitForApproval(1);

        // Step 1: Rejected
        FinCore::rejectJournalEntry($entry, approverId: 10, reason: 'Please attach supporting invoice');

        // Step 2: Maker resubmits
        $entry->returnToDraft('Attached invoice');
        $entry->submitForApproval(1);

        // Step 3: Approved
        FinCore::approveJournalEntry($entry, approverId: 20, comment: 'Looks good now');

        // Check approval history
        $history = FinCore::getApprovalHistory($entry);
        $this->assertCount(2, $history);

        $this->assertEquals('rejected', $history[0]->action);
        $this->assertEquals(10, $history[0]->approver_id);
        $this->assertEquals('Please attach supporting invoice', $history[0]->comment);

        $this->assertEquals('approved', $history[1]->action);
        $this->assertEquals(20, $history[1]->approver_id);
        $this->assertEquals('Looks good now', $history[1]->comment);
    }
}
