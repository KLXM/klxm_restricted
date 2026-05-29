<?php

declare(strict_types=1);

namespace KLXM\Restricted\Frontend;

use rex_sql;
use rex_sql_exception;
use KLXM\Restricted\User;

class UserController
{
    /**
     * Registers a new user.
     *
     * @return array{status: bool, message: string}
     */
    public function register(string $email, string $password, string $firstname, string $lastname, int $defaultRoleId = 0): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => false, 'message' => 'Ungültige E-Mail-Adresse.'];
        }

        if (strlen($password) < 6) {
            return ['status' => false, 'message' => 'Das Passwort muss mindestens 6 Zeichen lang sein.'];
        }

        $sql = rex_sql::factory();
        $sql->setQuery('SELECT id FROM ' . \rex::getTable('klxm_restricted_user') . ' WHERE email = ?', [$email]);
        if ($sql->getRows() > 0) {
            return ['status' => false, 'message' => 'Diese E-Mail-Adresse ist bereits registriert.'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $sql->setTable(\rex::getTable('klxm_restricted_user'));
        $sql->setValue('email', $email);
        $sql->setValue('password', $hash);
        $sql->setValue('firstname', $firstname);
        $sql->setValue('lastname', $lastname);
        if ($defaultRoleId > 0) {
            $sql->setValue('role_id', $defaultRoleId);
        }
        $sql->setValue('status', 1); // Activate by default

        try {
            $sql->insert();
            return ['status' => true, 'message' => 'Registrierung erfolgreich. Sie können sich nun einloggen.'];
        } catch (rex_sql_exception $e) {
            return ['status' => false, 'message' => 'Fehler bei der Registrierung.'];
        }
    }

    /**
     * Updates profile data (name, email) for a user.
     *
     * @return array{status: bool, message: string}
     */
    public function updateProfile(User $user, string $email, string $firstname, string $lastname): array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => false, 'message' => 'Ungültige E-Mail-Adresse.'];
        }

        $sql = rex_sql::factory();
        $sql->setQuery('SELECT id FROM ' . \rex::getTable('klxm_restricted_user') . ' WHERE email = ? AND id != ?', [$email, $user->id]);
        if ($sql->getRows() > 0) {
            return ['status' => false, 'message' => 'Diese E-Mail-Adresse ist bereits in Verwendung.'];
        }

        $sql->setTable(\rex::getTable('klxm_restricted_user'));
        $sql->setWhere(['id' => $user->id]);
        $sql->setValue('email', $email);
        $sql->setValue('firstname', $firstname);
        $sql->setValue('lastname', $lastname);

        try {
            $sql->update();
            return ['status' => true, 'message' => 'Profil erfolgreich aktualisiert.'];
        } catch (rex_sql_exception $e) {
            return ['status' => false, 'message' => 'Fehler beim Speichern des Profils.'];
        }
    }

    /**
     * Updates the password.
     *
     * @return array{status: bool, message: string}
     */
    public function updatePassword(User $user, string $oldPassword, string $newPassword): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT password FROM ' . \rex::getTable('klxm_restricted_user') . ' WHERE id = ?', [$user->id]);
        
        if ($sql->getRows() === 0) {
            return ['status' => false, 'message' => 'Benutzer nicht gefunden.'];
        }

        $hash = (string) $sql->getValue('password');
        if (!password_verify($oldPassword, $hash)) {
            return ['status' => false, 'message' => 'Das aktuelle Passwort ist nicht korrekt.'];
        }

        if (strlen($newPassword) < 6) {
            return ['status' => false, 'message' => 'Das neue Passwort muss mindestens 6 Zeichen lang sein.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $sql->setTable(\rex::getTable('klxm_restricted_user'));
        $sql->setWhere(['id' => $user->id]);
        $sql->setValue('password', $newHash);
        
        try {
            $sql->update();
            return ['status' => true, 'message' => 'Passwort erfolgreich geändert.'];
        } catch (rex_sql_exception $e) {
            return ['status' => false, 'message' => 'Fehler beim Ändern des Passworts.'];
        }
    }

    /**
     * Retrieves passkeys for the user.
     *
     * @return list<array<string, mixed>>
     */
    public function getPasskeys(User $user): array
    {
        $sql = rex_sql::factory();
        $keys = $sql->getArray('SELECT id, credential_id, created_at FROM ' . \rex::getTable('klxm_restricted_passkey') . ' WHERE user_handle = ?', [(string)$user->id]);
        
        foreach($keys as &$key) {
           $key['name'] = 'WebAuthn Token (' . substr((string) $key['credential_id'], 0, 8) . '...'  . ')';
        }

        return $keys;
    }
}
