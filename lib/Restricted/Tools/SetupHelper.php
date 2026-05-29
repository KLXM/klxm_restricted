<?php

declare(strict_types=1);

namespace KLXM\Restricted\Tools;

use rex_sql;
use rex;
use Error;
use rex_password_policy;

class SetupHelper
{
    /**
     * Creates a default Admin Role and an Admin Frontend User if the tables are empty.
     */
    public static function createDefaultUserIfEmpty(): void
    {
        $sql = rex_sql::factory();
        
        $roleTable = rex::getTable('klxm_restricted_role');
        $sql->setQuery("SELECT id FROM $roleTable LIMIT 1");
        
        if ($sql->getRows() === 0) {
            // Create initial Role
            $sql->setTable($roleTable);
            $sql->setValue('name', 'Admin / VIP');
            $sql->setValue('status', 1);
            $sql->insert();
            $roleId = (int)$sql->getLastId();

            // Create initial User
            $userTable = rex::getTable('klxm_restricted_user');
            $sql->setQuery("SELECT id FROM $userTable LIMIT 1");
            if ($sql->getRows() === 0) {
                $password = 'admin123!'; // We will securely hash this right now
                $hash = self::hashPassword($password);

                $sql->setTable($userTable);
                $sql->setValue('email', 'admin@example.com');
                $sql->setValue('password', $hash);
                $sql->setValue('firstname', 'Admin');
                $sql->setValue('lastname', 'User');
                $sql->setValue('role_id', $roleId);
                $sql->setValue('status', 1);
                $sql->insert();
            }
        }
    }

    private static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
