<?php

declare(strict_types=1);

namespace KLXM\Restricted\Frontend;

use KLXM\Restricted\Auth;
use rex_csrf_token;
use rex_url;

/**
 * Renders a small "logged in as …" status widget for use in frontend modules or templates.
 *
 * Usage in a REDAXO module output:
 *   echo \KLXM\Restricted\Frontend\UserWidget::render();
 */
class UserWidget
{
    /**
     * Returns an HTML snippet showing the current login state.
     *
     * When impersonation is active, an additional notice is shown.
     */
    public static function render(): string
    {
        $auth = new Auth();

        if (!$auth->isLoggedIn()) {
            return '';
        }

        $user = $auth->getUser();
        $name = htmlspecialchars(trim($user->firstname . ' ' . $user->lastname) ?: $user->email);
        $logoutUrl = htmlspecialchars(\rex_getUrl(\rex_article::getCurrentId()) . '?klxm_action=logout');

        $impersonateNotice = '';
        if ($auth->isImpersonated()) {
            $byName = htmlspecialchars($auth->getImpersonatedByName());
            $stopUrl = rex_url::backendController(array_merge([
                'page' => 'klxm_restricted/users',
                'func' => 'klxm_stop_impersonate',
            ], rex_csrf_token::factory('klxm_restricted_impersonate')->getUrlParams()));
            $impersonateNotice = '<span class="klxm-restricted-impersonate-notice"> &mdash; Ansicht als Admin: ' . $byName . ' | <a href="' . $stopUrl . '">Beenden</a></span>';
        }

        return '<div class="klxm-restricted-widget">'
            . 'Angemeldet als <strong>' . $name . '</strong>'
            . ' | <a href="' . $logoutUrl . '">Abmelden</a>'
            . $impersonateNotice
            . '</div>';
    }
}
