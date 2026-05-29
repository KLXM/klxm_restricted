<?php

declare(strict_types=1);

namespace KLXM\Restricted\Auth;

use rex;
use rex_addon;
use rex_request;
use rex_sql;

class SessionStore
{
    public static function storeCurrentSession(int $userId): void
    {
        $sessionId = self::getSessionId();
        if ($sessionId === null) {
            return;
        }

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_session'));
        $sql->setValue('session_id', $sessionId);
        $sql->setValue('user_id', $userId);
        $sql->setValue('ip', rex_request::server('REMOTE_ADDR', 'string'));
        $sql->setValue('useragent', self::truncateUserAgent(rex_request::server('HTTP_USER_AGENT', 'string')));
        $sql->setValue('starttime', rex_sql::datetime(time()));
        $sql->setValue('last_activity', rex_sql::datetime(time()));
        $sql->insertOrUpdate();
    }

    public static function touchCurrentSession(int $userId): void
    {
        $sessionId = self::getSessionId();
        if ($sessionId === null) {
            return;
        }

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_session'));
        $sql->setWhere('session_id = ? AND user_id = ?', [$sessionId, $userId]);
        $sql->setValue('last_activity', rex_sql::datetime(time()));
        $sql->update();
    }

    public static function clearCurrentSession(): void
    {
        $sessionId = self::getSessionId();
        if ($sessionId === null) {
            return;
        }

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_session'));
        $sql->setWhere('session_id = ?', [$sessionId]);
        $sql->delete();
    }

    public static function isCurrentSessionValid(int $userId): bool
    {
        $sessionId = self::getSessionId();
        if ($sessionId === null) {
            return false;
        }

        $sql = rex_sql::factory();
        $rows = $sql->getArray(
            'SELECT 1 FROM ' . rex::getTable('klxm_restricted_session') . ' WHERE session_id = ? AND user_id = ? LIMIT 1',
            [$sessionId, $userId]
        );

        return count($rows) === 1;
    }

    public static function clearExpiredSessions(): void
    {
        $timeoutMinutes = (int) rex_addon::get('klxm_restricted')->getConfig('session_timeout_minutes', 120);
        $maxLifetimeMinutes = (int) rex_addon::get('klxm_restricted')->getConfig('session_max_lifetime_minutes', 1440);

        $timeoutSeconds = max(60, $timeoutMinutes * 60);
        $maxLifetimeSeconds = max(300, $maxLifetimeMinutes * 60);

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_session'));
        $sql->setWhere(
            '(UNIX_TIMESTAMP(last_activity) < :last_activity OR UNIX_TIMESTAMP(starttime) < :starttime)',
            [
                ':last_activity' => time() - $timeoutSeconds,
                ':starttime' => time() - $maxLifetimeSeconds,
            ]
        );
        $sql->delete();
    }

    private static function getSessionId(): ?string
    {
        $sessionId = session_id();
        if ($sessionId === false || $sessionId === '') {
            return null;
        }

        return $sessionId;
    }

    private static function truncateUserAgent(string $userAgent): string
    {
        if (strlen($userAgent) <= 255) {
            return $userAgent;
        }

        return substr($userAgent, 0, 255);
    }
}
