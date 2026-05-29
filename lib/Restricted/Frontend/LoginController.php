<?php

declare(strict_types=1);

namespace KLXM\Restricted\Frontend;

use rex_clang;
use KLXM\Restricted\Auth;
use KLXM\Restricted\PermissionManager;
use rex_csrf_token;
use rex_addon;
use rex_article;
use rex_fragment;
use rex_request;
use rex_response;

class LoginController
{
    public static function processRequest(): string
    {
        $addon = rex_addon::get('klxm_restricted');
        $auth = new Auth();
        $error = null;
        $requestFeedback = null;

        // CSRF/Action Check could go here in a full release
        $email = rex_request::post('klxm_login_email', 'string', '');
        $password = rex_request::post('klxm_login_password', 'string', '');
        $redirectParam = rex_request::get('redirect_to', 'int', 0);
        $returnTo = trim(rex_request::get('returnTo', 'string', ''));
        $isSafeReturnTo = $returnTo !== '' && str_starts_with($returnTo, '/') && !str_contains($returnTo, '://');
        $currentPath = (string) parse_url((string) rex_request::server('REQUEST_URI', 'string', ''), PHP_URL_PATH);
        $returnToMatchesCurrentPage = $isSafeReturnTo && $currentPath !== '' && rtrim($currentPath, '/') === rtrim($returnTo, '/');

        $requestTargetDefault = $redirectParam;
        if ($requestTargetDefault === 0 && $returnToMatchesCurrentPage) {
            $requestTargetDefault = rex_article::getCurrentId();
        }

        $requestArticleId = rex_request::post('klxm_request_article_id', 'int', $requestTargetDefault);
        $pm = new PermissionManager();
        $requestEnabled = $requestArticleId > 0 && $pm->isAccessRequestEnabledForArticle($requestArticleId);
        $allowGuestRequests = (bool) $addon->getConfig('allow_guest_access_requests', true);

        // 1. Handle Logout
        if (rex_request::get('klxm_action', 'string') === 'logout') {
            $auth->logout();
            // Redirect to home or configured logout page
            rex_response::sendRedirect(\rex_getUrl(rex_article::getSiteStartArticleId()));
        }

        // Optional one-click access request for restricted targets
        if (rex_request::post('klxm_action', 'string') === 'request_access') {
            if (!rex_csrf_token::factory('klxm_restricted_request_access')->isValid()) {
                $requestFeedback = ['status' => false, 'message' => 'Aktion abgelehnt (ungueltiger CSRF-Token).'];
            } elseif (!$requestEnabled) {
                $requestFeedback = ['status' => false, 'message' => 'Anfragen sind für diesen Inhalt deaktiviert.'];
            } elseif (!$allowGuestRequests && !$auth->isLoggedIn()) {
                $requestFeedback = ['status' => false, 'message' => 'Bitte zuerst einloggen, um eine Anfrage zu stellen.'];
            } else {
                $requestEmail = rex_request::post('klxm_request_email', 'string', '');
                $requestMessage = rex_request::post('klxm_request_message', 'string', '');
                $requestFeedback = AccessRequestService::createForArticle($requestArticleId, $auth->getUser(), $requestEmail, $requestMessage, $allowGuestRequests);
            }
        }

        // 2. Already Logged In? -> Show status / Redirect
        if ($auth->isLoggedIn()) {
            if ($requestEnabled && ($redirectParam > 0 || $returnToMatchesCurrentPage)) {
                $requestFormEmail = $auth->getUser()->email;

                return '<div class="alert alert-info">Sie sind eingeloggt, haben aber noch keinen Zugriff auf diesen Inhalt.</div>'
                    . self::renderAccessRequestForm($requestArticleId, $requestFeedback, $requestFormEmail);
            }

            $redirectArticle = (int) $addon->getConfig('redirect_article_after_login');
            if ($redirectArticle > 0 && rex_article::getCurrentId() !== $redirectArticle) {
                 rex_response::sendRedirect(\rex_getUrl($redirectArticle));
            }
            return '<div class="alert alert-success">Sie sind bereits eingeloggt als ' . htmlspecialchars($auth->getUser()->email) . '. <a href="?klxm_action=logout">Abmelden</a></div>';
        }

        // 3. Handle Login Form Submit
        if ($email !== '' && $password !== '') {
            if ($auth->login($email, $password)) {
                // Determine Redirect Goal
                $redirectTo = rex_request::get('redirect_to', 'int', 0);
                if ($redirectTo > 0) {
                    rex_response::sendRedirect(\rex_getUrl($redirectTo));
                } elseif ($isSafeReturnTo) {
                    rex_response::sendRedirect($returnTo);
                } else {
                    $redirectArticle = (int) $addon->getConfig('redirect_article_after_login');
                    $target = $redirectArticle > 0 ? $redirectArticle : rex_article::getSiteStartArticleId();
                    rex_response::sendRedirect(\rex_getUrl($target));
                }
            }

            $error = match ($auth->getLastLoginError()) {
                Auth::LOGIN_ERROR_LOCKED => 'Zu viele Fehlversuche. Ihr Konto ist vorübergehend gesperrt.',
                Auth::LOGIN_ERROR_UNVERIFIED => 'Bitte bestätigen Sie zuerst Ihre E-Mail-Adresse.',
                default => 'Login fehlgeschlagen. E-Mail oder Passwort falsch.',
            };
        }

        // 4. Render Form Fragment
        $framework = $addon->getConfig('theme_framework', 'bootstrap');
        $fragment = new rex_fragment();
        
        // Preserve redirect_to parameter in the form action
        $actionUrl = \rex_getUrl(rex_article::getCurrentId(), rex_clang::getCurrentId());
        if ($redirectParam > 0) {
            $actionUrl .= (str_contains($actionUrl, '?') ? '&' : '?') . 'redirect_to=' . $redirectParam;
        } elseif ($isSafeReturnTo) {
            $actionUrl .= (str_contains($actionUrl, '?') ? '&' : '?') . 'returnTo=' . rawurlencode($returnTo);
        }

        $fragment->setVar('action_url', $actionUrl, false);
        $fragment->setVar('error', $error, false);
        $fragment->setVar('passkey_enabled', false, false); // Placeholder

        $fragmentPath = 'restricted/' . $framework . '/login.php';
        
        // Fallback to bootstrap if specific framework file doesn't exist
        if (!file_exists($addon->getPath('fragments/' . $fragmentPath))) {
            $fragmentPath = 'restricted/bootstrap/login.php';
        }

        $output = $fragment->parse($fragmentPath);

        if ($requestEnabled && ($allowGuestRequests || $auth->isLoggedIn())) {
            $requestFormEmail = rex_request::post('klxm_request_email', 'string', '');
            $output .= self::renderAccessRequestForm($requestArticleId, $requestFeedback, $requestFormEmail);
        } elseif ($requestEnabled) {
            $output .= '<div class="alert alert-info" style="margin-top:20px;">'
                . 'Zugriffsanfragen sind nur für eingeloggte Nutzer möglich.</div>';
        }

        return $output;
    }

    /**
     * @param array{status: bool, message: string}|null $feedback
     */
    private static function renderAccessRequestForm(int $articleId, ?array $feedback, string $prefillEmail): string
    {
        $tokenValue = rex_csrf_token::factory('klxm_restricted_request_access')->getValue();
        $html = '<div class="panel panel-default" style="margin-top:20px;">';
        $html .= '<div class="panel-heading"><strong>Zugriff anfragen</strong></div>';
        $html .= '<div class="panel-body">';
        $html .= '<p>Sie können mit einem Klick Zugriff auf diesen Inhalt anfragen.</p>';

        if ($feedback !== null) {
            $alertClass = $feedback['status'] ? 'alert-success' : 'alert-danger';
            $html .= '<div class="alert ' . $alertClass . '">' . htmlspecialchars($feedback['message']) . '</div>';
        }

        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($tokenValue) . '">';
        $html .= '<input type="hidden" name="klxm_action" value="request_access">';
        $html .= '<input type="hidden" name="klxm_request_article_id" value="' . (int) $articleId . '">';
        $html .= '<div class="form-group">';
        $html .= '<label>E-Mail</label>';
        $html .= '<input class="form-control" type="email" name="klxm_request_email" value="' . htmlspecialchars($prefillEmail) . '" required>';
        $html .= '</div>';
        $html .= '<div class="form-group">';
        $html .= '<label>Grund (optional)</label>';
        $html .= '<textarea class="form-control" name="klxm_request_message" rows="3"></textarea>';
        $html .= '</div>';
        $html .= '<button type="submit" class="btn btn-primary">Zugriff anfragen</button>';
        $html .= '</form>';
        $html .= '</div></div>';

        return $html;
    }
}
