<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use KLXM\Restricted\Auth\LoginThrottle;
use KLXM\Restricted\Auth\SessionStore as AuthSessionStore;
use rex;
use rex_addon;
use rex_login;
use rex_sql;

class Auth
{
    public const LOGIN_ERROR_INVALID = 'invalid_credentials';
    public const LOGIN_ERROR_LOCKED = 'account_locked';
    public const LOGIN_ERROR_UNVERIFIED = 'email_not_verified';

    private const SESSION_KEY = 'klxm_restricted_user_id';
    private const IMPERSONATE_KEY = 'klxm_restricted_impersonate';

    private ?User $currentUser = null;
    private bool $initialized = false;
    private string $lastLoginError = '';
    private bool $impersonated = false;
    private string $impersonatedByName = '';

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        if ($this->initialized) {
            return;
        }

        rex_login::startSession();
        AuthSessionStore::clearExpiredSessions();

        // Check for impersonation (set by backend admin)
        $impersonate = rex_session(self::IMPERSONATE_KEY, 'array', []);
        if (isset($impersonate['user_id']) && (int) $impersonate['user_id'] > 0) {
            $user = User::findById((int) $impersonate['user_id']);
            if ($user !== null) {
                $this->currentUser = $user;
                $this->impersonated = true;
                $this->impersonatedByName = (string) ($impersonate['by_name'] ?? '');
                $this->initialized = true;
                return;
            }
            // Impersonation target no longer valid — clear session key
            rex_unset_session(self::IMPERSONATE_KEY);
        }

        // Regular session login
        $userId = rex_session(self::SESSION_KEY, 'int', 0);
        if ($userId > 0) {
            if (!AuthSessionStore::isCurrentSessionValid($userId)) {
                $this->logout();
                $this->initialized = true;
                return;
            }

            $user = User::findById($userId);
            if ($user !== null) {
                $this->currentUser = $user;
                AuthSessionStore::touchCurrentSession($user->id);
            } else {
                $this->logout();
            }
        }

        $this->initialized = true;
    }

    public function login(string $email, string $password): bool
    {
        $user = User::findByEmail($email);

        if ($user === null) {
            // Dummy verify to prevent timing attacks
            password_verify($password, '$2y$10$invalidhashpadding000000000000000000000000000000000000');
            $this->lastLoginError = self::LOGIN_ERROR_INVALID;
            return false;
        }

        // Check lockout
        if (LoginThrottle::isLocked($user->id)) {
            $this->lastLoginError = self::LOGIN_ERROR_LOCKED;
            return false;
        }

        // Verify password from DB (User::findByEmail only returns active users, so re-query for password)
        $sql = rex_sql::factory();
        $sql->setQuery('SELECT password FROM ' . rex::getTable('klxm_restricted_user') . ' WHERE id = ?', [$user->id]);
        $hash = $sql->getRows() === 1 ? (string) $sql->getValue('password') : '';

        if (!password_verify($password, $hash)) {
            LoginThrottle::recordFailure($user->id);
            $this->lastLoginError = self::LOGIN_ERROR_INVALID;
            return false;
        }

        // Check email verification
        $requireVerification = (bool) rex_addon::get('klxm_restricted')->getConfig('require_email_verification', false);
        if ($requireVerification && !$user->emailVerified) {
            $this->lastLoginError = self::LOGIN_ERROR_UNVERIFIED;
            return false;
        }

        LoginThrottle::recordSuccess($user->id);
        rex_login::regenerateSessionId();
        rex_set_session(self::SESSION_KEY, $user->id);
        AuthSessionStore::storeCurrentSession($user->id);
        $this->currentUser = User::findById($user->id);
        $this->lastLoginError = '';
        return true;
    }

    /**
     * Logs in a user by ID directly (used for impersonation and passkey flow).
     */
    public function loginById(int $userId): bool
    {
        $user = User::findById($userId);
        if ($user !== null) {
            rex_login::regenerateSessionId();
            rex_set_session(self::SESSION_KEY, $userId);
            AuthSessionStore::storeCurrentSession($userId);
            $this->currentUser = $user;
            return true;
        }
        return false;
    }

    /**
     * Passkey / WebAuthn Stub for future integration
     */
    public function loginWithPasskey(string $challengeResponse): bool
    {
        // TODO: Implement WebAuthn validation
        return false;
    }

    public function logout(): void
    {
        AuthSessionStore::clearCurrentSession();
        rex_unset_session(self::SESSION_KEY);
        rex_unset_session(self::IMPERSONATE_KEY);
        $this->currentUser = null;
        $this->impersonated = false;
    }

    public function getUser(): ?User
    {
        return $this->currentUser;
    }

    /**
     * @phpstan-assert-if-true !null $this->getUser()
     */
    public function isLoggedIn(): bool
    {
        return $this->currentUser !== null;
    }

    /**
     * Returns the last login error code (one of the LOGIN_ERROR_* constants) or empty string.
     */
    public function getLastLoginError(): string
    {
        return $this->lastLoginError;
    }

    /**
     * Returns true when a backend admin is currently impersonating this session.
     */
    public function isImpersonated(): bool
    {
        return $this->impersonated;
    }

    /**
     * Returns the name of the backend user who started the impersonation.
     */
    public function getImpersonatedByName(): string
    {
        return $this->impersonatedByName;
    }

    /**
     * Starts an impersonation session. Only call from trusted backend context.
     */
    public static function startImpersonation(int $userId, string $byName): bool
    {
        rex_login::startSession();
        $user = User::findById($userId);
        if ($user === null) {
            return false;
        }

        rex_set_session(self::IMPERSONATE_KEY, [
            'user_id' => $userId,
            'by_name' => $byName,
        ]);

        return true;
    }

    /**
     * Stops the current impersonation session.
     */
    public static function stopImpersonation(): void
    {
        rex_unset_session(self::IMPERSONATE_KEY);
    }
}
