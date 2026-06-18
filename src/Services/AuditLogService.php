<?php

namespace Nml\FinCore\Services;

use Nml\FinCore\Models\AuditLog;

class AuditLogService
{
    /**
     * Log an action to the audit logs.
     */
    public static function log(
        string $action,
        ?int $journalEntryId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ): AuditLog {
        $ip = null;
        $userAgent = null;

        // Safely extract request details if running in web context
        if (function_exists('request') && request()) {
            $ip = request()->ip();
            $userAgent = request()->userAgent();
        }

        return AuditLog::create([
            'journal_entry_id' => $journalEntryId,
            'action'           => $action,
            'user_id'          => $userId,
            'ip_address'       => $ip,
            'user_agent'       => $userAgent,
            'old_values'       => $oldValues,
            'new_values'       => $newValues,
        ]);
    }
}
