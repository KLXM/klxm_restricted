<?php

declare(strict_types=1);

namespace KLXM\Restricted\Backend;

use KLXM\Restricted\Auth;
use KLXM\Restricted\User;
use rex;
use rex_addon;

/**
 * Handles start and stop of backend-admin impersonation of frontend users.
 * Used by pages/impersonate.php.
 */
class ImpersonateHandler
{
    /**
     * Starts impersonation. Returns error message or empty string on success.
     * On success, also returns the frontend URL to open via $frontendUrl.
     *
     * @return array{error: string, frontendUrl: string}
     */
    public static function start(int $userId): array
    {
        $backendUser = rex::getUser();
        if ($backendUser === null || !$backendUser->isAdmin()) {
            return ['error' => 'Nicht autorisiert.', 'frontendUrl' => ''];
        }

        $user = User::findById($userId);
        if ($user === null) {
            return ['error' => 'Nutzer nicht gefunden.', 'frontendUrl' => ''];
        }

        $byName = (string) $backendUser->getName();
        if (!Auth::startImpersonation($userId, $byName)) {
            return ['error' => 'Impersonation konnte nicht gestartet werden.', 'frontendUrl' => ''];
        }

        $loginArticle = (int) rex_addon::get('klxm_restricted')->getConfig('login_article', 0);
        $frontendUrl = $loginArticle > 0 ? \rex_getUrl($loginArticle) : \rex_getUrl(\rex_article::getSiteStartArticleId());

        return ['error' => '', 'frontendUrl' => $frontendUrl];
    }

    /**
     * Stops the current impersonation session.
     */
    public static function stop(): void
    {
        Auth::stopImpersonation();
    }
}
