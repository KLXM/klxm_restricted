<?php

declare(strict_types=1);

namespace KLXM\Restricted\Auth;

use rex;
use rex_addon;
use rex_sql;

/**
 * Handles login attempt tracking and brute-force lockout.
 */
class LoginThrottle
{
    /**
     * Returns true if the given user is currently locked out.
     */
    public static function isLocked(int $userId): bool
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT login_locked_until FROM ' . rex::getTable('klxm_restricted_user') . ' WHERE id = ?',
            [$userId]
        );

        if ($sql->getRows() !== 1) {
            return false;
        }

        $lockedUntil = $sql->getValue('login_locked_until');
        if ($lockedUntil === null || $lockedUntil === '') {
            return false;
        }

        return strtotime((string) $lockedUntil) > time();
    }

    /**
     * Records a failed login attempt. Locks the account if threshold is reached.
     */
    public static function recordFailure(int $userId): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT failed_logins FROM ' . rex::getTable('klxm_restricted_user') . ' WHERE id = ?',
            [$userId]
        );

        if ($sql->getRows() !== 1) {
            return;
        }

        $failed = (int) ($sql->getValue('failed_logins') ?? 0) + 1;
        $maxAttempts = self::getMaxAttempts();

        $update = rex_sql::factory();
        $update->setTable(rex::getTable('klxm_restricted_user'));
        $update->setWhere(['id' => $userId]);
        $update->setValue('failed_logins', $failed);

        if ($failed >= $maxAttempts) {
            $lockoutSeconds = self::getLockoutMinutes() * 60;
            $update->setValue('login_locked_until', date('Y-m-d H:i:s', time() + $lockoutSeconds));
        }

        $update->update();
    }

    /**
     * Records a successful login: clears failed attempts, sets last_login.
     */
    public static function recordSuccess(int $userId): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_user'));
        $sql->setWhere(['id' => $userId]);
        $sql->setValue('failed_logins', 0);
        $sql->setValue('login_locked_until', '');
        $sql->setValue('last_login', date('Y-m-d H:i:s'));
        $sql->update();
    }

    /**
     * Returns how many seconds remain until the lockout expires (0 if not locked).
     */
    public static function getLockoutRemainingSeconds(int $userId): int
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT login_locked_until FROM ' . rex::getTable('klxm_restricted_user') . ' WHERE id = ?',
            [$userId]
        );

        if ($sql->getRows() !== 1) {
            return 0;
        }

        $lockedUntil = $sql->getValue('login_locked_until');
        if ($lockedUntil === null || $lockedUntil === '') {
            return 0;
        }

        $remaining = strtotime((string) $lockedUntil) - time();
        return max(0, $remaining);
    }

    private static function getMaxAttempts(): int
    {
        return (int) rex_addon::get('klxm_restricted')->getConfig('max_login_attempts', 5);
    }

    private static function getLockoutMinutes(): int
    {
        return (int) rex_addon::get('klxm_restricted')->getConfig('lockout_minutes', 15);
    }
}
