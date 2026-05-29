<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex;
use rex_sql;

class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $firstname,
        public readonly string $lastname,
        public readonly ?int $roleId,
        public readonly ?string $lastLogin = null,
        public readonly int $failedLogins = 0,
        public readonly ?string $loginLockedUntil = null,
        public readonly bool $emailVerified = true
    ) {}

    public static function findById(int $id): ?self
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('klxm_restricted_user') . ' WHERE id = ? AND status = 1', [$id]);

        if ($sql->getRows() === 1) {
            return self::fromRow($sql);
        }

        return null;
    }

    public static function findByEmail(string $email): ?self
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT * FROM ' . rex::getTable('klxm_restricted_user') . ' WHERE email = ? AND status = 1', [$email]);

        if ($sql->getRows() === 1) {
            return self::fromRow($sql);
        }

        return null;
    }

    private static function fromRow(rex_sql $sql): self
    {
        return new self(
            id: (int) $sql->getValue('id'),
            email: (string) $sql->getValue('email'),
            firstname: (string) $sql->getValue('firstname'),
            lastname: (string) $sql->getValue('lastname'),
            roleId: $sql->getValue('role_id') ? (int) $sql->getValue('role_id') : null,
            lastLogin: $sql->getValue('last_login') ? (string) $sql->getValue('last_login') : null,
            failedLogins: (int) ($sql->getValue('failed_logins') ?? 0),
            loginLockedUntil: $sql->getValue('login_locked_until') ? (string) $sql->getValue('login_locked_until') : null,
            emailVerified: (bool) ($sql->getValue('email_verified') ?? true)
        );
    }

    public function hasRole(int $roleId): bool
    {
        return $this->roleId === $roleId;
    }
}