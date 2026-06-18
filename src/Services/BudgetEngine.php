<?php

namespace Nml\FinCore\Services;

use Nml\FinCore\Models\Budget;
use Nml\FinCore\Models\Account;

class BudgetEngine
{
    /**
     * Set or update monthly budget target for an account.
     */
    public function setMonthlyBudget(
        int $accountId,
        int $year,
        int $month,
        float $amount,
        ?string $sbuCode = null
    ): Budget {
        return Budget::updateOrCreate([
            'account_id'  => $accountId,
            'sbu_code'    => $sbuCode,
            'fiscal_year' => $year,
            'month'       => $month,
        ], [
            'amount'      => $amount,
        ]);
    }

    /**
     * Get Budget vs Actual variance report for a specific month.
     */
    public function getBudgetVarianceReport(int $year, int $month, ?string $sbuCode = null): array
    {
        $startDate = "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
        $endDate = date('Y-m-t', strtotime($startDate));

        $query = Budget::with('account')
            ->where('fiscal_year', $year)
            ->where('month', $month);

        if ($sbuCode !== null) {
            $query->where('sbu_code', $sbuCode);
        } else {
            $query->whereNull('sbu_code');
        }

        $budgets = $query->get();

        if ($budgets->isEmpty()) {
            return [];
        }

        $accountIds = $budgets->pluck('account_id')->unique()->toArray();

        // Get actual ledger balances for the period
        $ledgerEngine = new LedgerEngine();
        $actualBalances = $ledgerEngine->getBalancesForAccounts($accountIds, $sbuCode, $startDate, $endDate);

        $report = [];

        foreach ($budgets as $budget) {
            $account = $budget->account;
            if (!$account) {
                continue;
            }

            $budgetAmount = (float) $budget->amount;
            $actualAmount = (float) ($actualBalances[$account->id] ?? 0.0);

            // Expense accounts: Favorable if actual is less than budget
            // Revenue accounts: Favorable if actual is more than budget
            $isExpense = ($account->type->value === 'expense' || $account->type->value === 'cost_of_goods_sold');
            
            if ($isExpense) {
                $variance = $budgetAmount - $actualAmount;
            } else {
                $variance = $actualAmount - $budgetAmount;
            }

            $status = $variance >= 0 ? 'Favorable' : 'Unfavorable';
            $variancePercentage = $budgetAmount > 0
                ? round(($variance / $budgetAmount) * 100, 2)
                : ($actualAmount > 0 ? 100.0 : 0.0);

            $report[] = [
                'account_id'          => $account->id,
                'account_name'        => $account->name,
                'account_code'        => $account->code,
                'account_type'        => $account->type->value,
                'budget_amount'       => $budgetAmount,
                'actual_amount'       => $actualAmount,
                'variance'            => round($variance, 2),
                'variance_percentage' => $variancePercentage,
                'status'              => $status,
            ];
        }

        return $report;
    }
}
