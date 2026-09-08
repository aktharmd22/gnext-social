<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Who did what.
 *
 * Records the fact and the shape of a change, never its secrets: replacing an
 * app secret logs that it was replaced, not what it was replaced with.
 */
class ActivityLogger
{
    /**
     * Values that must never be written into the changes column, even though
     * knowing they changed is exactly what an audit trail is for.
     */
    private const NEVER_RECORD = [
        'meta_app_secret',
        'webhook_verify_token',
        'access_token',
        'telegram_bot_token',
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * @param  array<string, mixed>  $changes
     */
    public function log(string $action, ?Model $subject = null, array $changes = []): ActivityLog
    {
        $user = Auth::user();

        return ActivityLog::withoutGlobalScopes()->create([
            'workspace_id' => $subject?->getAttribute('workspace_id') ?? $user?->workspace_id,
            'user_id' => $user?->id,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'action' => $action,
            'changes' => $this->scrub($changes) ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 1000),
        ]);
    }

    /**
     * Attributes that describe the row rather than the change, and would only
     * add noise to every log entry.
     */
    private const UNINTERESTING = ['id', 'created_at', 'updated_at'];

    /**
     * Log a model's changed attributes, with secrets reduced to the fact that
     * they changed.
     */
    public function logChanges(string $action, Model $subject): ActivityLog
    {
        /*
         * getChanges() is empty after an INSERT: Laravel calls syncChanges()
         * only from performUpdate(), so a freshly created record reports no
         * changes at all. Without this branch, first-time setup -- the single
         * most interesting thing to audit -- would log nothing.
         */
        $source = $subject->wasRecentlyCreated
            ? $subject->getAttributes()
            : $subject->getChanges();

        $changes = [];

        foreach ($source as $attribute => $value) {
            if (in_array($attribute, self::UNINTERESTING, true)) {
                continue;
            }

            $changes[$attribute] = in_array($attribute, self::NEVER_RECORD, true)
                ? ['changed' => true]
                : ['to' => $value];
        }

        return $this->log($action, $subject, $changes);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function scrub(array $changes): array
    {
        foreach ($changes as $key => $value) {
            if (in_array($key, self::NEVER_RECORD, true)) {
                $changes[$key] = ['changed' => true];

                continue;
            }

            if (is_array($value)) {
                $changes[$key] = $this->scrub($value);
            }
        }

        return $changes;
    }
}
