<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use KLXM\Restricted\Media\MediaGuard;
use KLXM\Restricted\Media\BoardShareService;
use KLXM\Restricted\Cronjob\ZipJobCleanupCronjob;
use KLXM\Restricted\Backend\ArticleSidebar;
use KLXM\Restricted\Frontend\LoginController;
use KLXM\Restricted\Frontend\PastebinService;
use rex;
use rex_extension;
use rex_extension_point;
use rex_article;
use rex_category;
use rex_response;
use rex_addon;
use rex_config;
use rex_csrf_token;
use rex_clang;
use rex_request;
use rex_path;
use rex_cronjob_manager;
use rex_cronjob_manager_sql;
use rex_sql;
use rex_url;
use rex_view;
use rex_be_controller;
use rex_be_page;

if (rex_addon::get('cronjob')->isAvailable() && !rex::isSafeMode()) {
    rex_cronjob_manager::registerType(ZipJobCleanupCronjob::class);

    $cronSetupKey = 'zip_cleanup_cronjob_seeded';
    if (!rex_config::get('klxm_restricted', $cronSetupKey, false)) {
        $sql = rex_sql::factory();
        $existing = $sql->getArray(
            'SELECT id FROM ' . rex::getTable('cronjob') . ' WHERE type = ? LIMIT 1',
            [ZipJobCleanupCronjob::class]
        );

        if ($existing === []) {
            $interval = [
                'minutes' => '0',
                'hours' => 'all',
                'days' => 'all',
                'weekdays' => 'all',
                'month' => 'all',
            ];

            $sql->setTable(rex::getTable('cronjob'));
            $sql->setValue('name', '[klxm_restricted] ZIP-Job-Bereinigung');
            $sql->setValue('description', 'Entfernt abgelaufene Dateiablage-ZIP-Jobs und verwaiste ZIP-Dateien.');
            $sql->setValue('type', ZipJobCleanupCronjob::class);
            $sql->setValue('parameters', '[]');
            $sql->setValue('interval', (string) json_encode($interval));
            $sql->setValue('nexttime', rex_sql::datetime(rex_cronjob_manager_sql::calculateNextTime($interval)));
            $sql->setValue('environment', '|frontend|backend|script|');
            $sql->setValue('execution_moment', 0);
            $sql->setValue('execution_start', '0000-00-00 00:00:00');
            $sql->setValue('status', 1);
            $sql->addGlobalUpdateFields('klxm_restricted');
            $sql->addGlobalCreateFields('klxm_restricted');
            $sql->insert();
        }

        rex_config::set('klxm_restricted', $cronSetupKey, true);
    }
}

// Setup in the backend navigation for admin panel
if (rex::isBackend() && rex::getUser()) {
    rex_extension::register('PAGES_PREPARED', static function (): void {
        if (!rex_addon::get('ycom')->isAvailable()) {
            return;
        }

        $allowedSubpages = ['matrix', 'share_requests', 'pastebin', 'settings', 'editorial', 'help'];

        $filter = static function ($page) use ($allowedSubpages): void {
            if (!$page instanceof rex_be_page) {
                return;
            }

            $subpages = $page->getSubpages();
            foreach ($subpages as $key => $subpage) {
                $subpageKey = (string) $subpage->getKey();
                if (!in_array($subpageKey, $allowedSubpages, true)) {
                    unset($subpages[$key]);
                }
            }

            $page->setSubpages($subpages);
        };

        $filter(rex_be_controller::getPageObject('klxm_restricted'));
    });

    // Inject Matrix JS logic only on the respective backend page
    rex_extension::register('PACKAGES_INCLUDED', static function () {
        $addon = rex_addon::get('klxm_restricted');
        $ycomActive = rex_addon::get('ycom')->isAvailable();
        $currentPage = rawurldecode(rex_request::get('page', 'string'));

        if ($currentPage === 'klxm_restricted/matrix') {
            rex_view::addJsFile($addon->getAssetsUrl('matrix.js'));
        }

        if ($currentPage === 'mediapool/klxm_restricted_file_share') {
            rex_view::addJsFile($addon->getAssetsUrl('share-links.js'));
            rex_view::addJsFile($addon->getAssetsUrl('file-share.js'));
        }

        if ($currentPage === 'klxm_restricted/share_requests') {
            $echartsAddon = rex_addon::get('echarts');
            if ($echartsAddon->isAvailable()) {
                rex_view::addJsFile($echartsAddon->getAssetsUrl('echarts.min.js'));
            }
            rex_view::addJsFile($addon->getAssetsUrl('share-requests.js'));
        }

        // Article sidebar: show assigned roles for the current article
        if (!$ycomActive) {
            rex_extension::register('STRUCTURE_CONTENT_SIDEBAR', static function (rex_extension_point $ep): string {
                return ArticleSidebar::render($ep);
            });
        }
    });
}

// Frontend Permissions
if (rex::isFrontend()) {
    $ycomActive = rex_addon::get('ycom')->isAvailable();

    if (PastebinService::handleFrontendRequest()) {
        exit;
    }

    if (BoardShareService::handleFrontendDownloadRequest()) {
        exit;
    }

    if (BoardShareService::handleFrontendDirectShareRequest()) {
        exit;
    }

    if (!$ycomActive) {
        // Guard against self-referential returnTo loops from third-party auth forms.
        $returnTo = trim(rex_request::get('returnTo', 'string', ''));
        if ($returnTo !== '' && str_starts_with($returnTo, '/') && !str_starts_with($returnTo, '//') && !str_starts_with($returnTo, '/\\') && !str_contains($returnTo, '://')) {
            $currentPath = (string) parse_url((string) rex_request::server('REQUEST_URI', 'string', ''), PHP_URL_PATH);
            if ($currentPath !== '' && rtrim($currentPath, '/') === rtrim($returnTo, '/')) {
                rex_response::cleanOutputBuffers();
                rex_response::sendCacheControl();
                rex_response::sendContent(LoginController::processRequest());
                exit;
            }
        }
    }

    if (!$ycomActive) {
        // 1. Article Access Check (early hook similar to accessdenied)
        rex_extension::register('PACKAGES_INCLUDED', static function (rex_extension_point $ep) {
        $articleId = rex_article::getCurrentId();
        if ($articleId === 0) {
            return;
        }

        $auth = new Auth();
        $permissionManager = new PermissionManager();
        
        $configuredLoginArticleId = (int) rex_addon::get('klxm_restricted')->getConfig('login_article');
        $loginArticleId = $configuredLoginArticleId;
        if ($loginArticleId === 0) {
            $loginArticleId = (int) rex_article::getSiteStartArticleId();
        }

        // Never bypass protection silently on login article.
        // If login article itself is restricted, render login output directly to avoid loops.
        if ($configuredLoginArticleId > 0 && $articleId === $configuredLoginArticleId && !$permissionManager->checkArticleAccess($auth->getUser(), $articleId)) {
            rex_response::cleanOutputBuffers();
            rex_response::sendCacheControl();
            rex_response::sendContent(LoginController::processRequest());
            exit;
        }

        if ($configuredLoginArticleId > 0 && $articleId === $configuredLoginArticleId) {
            return;
        }

        if (!$permissionManager->checkArticleAccess($auth->getUser(), $articleId)) {
            if ($configuredLoginArticleId === 0 && $articleId === $loginArticleId) {
                rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
                rex_response::sendCacheControl();
                rex_response::sendContent('Access denied. Bitte in den Addon-Einstellungen einen Login-Artikel konfigurieren.');
                exit;
            }

            // Access Denied! Redirect to login page.
            // Use '&' as separator (not default '&amp;') to avoid HTML-encoded URLs in HTTP redirects
            rex_response::sendRedirect(\rex_getUrl($loginArticleId, rex_clang::getCurrentId(), ['redirect_to' => rex_article::getCurrentId()], '&'));
        }
        });
    }

    if (!$ycomActive) {
        // Compatibility guard: prevent endless self-redirects emitted by YCom auth init.
        rex_extension::register('YCOM_AUTH_INIT', static function (rex_extension_point $ep): array {
        /** @var array<string, mixed> $params */
        $params = $ep->getSubject();
        $redirect = (string) ($params['redirect'] ?? '');
        if ($redirect === '') {
            return $params;
        }

        $requestUri = (string) rex_request::server('REQUEST_URI', 'string', '');
        $currentPath = (string) parse_url($requestUri, PHP_URL_PATH);
        $currentQuery = (string) parse_url($requestUri, PHP_URL_QUERY);

        $redirectPath = (string) parse_url($redirect, PHP_URL_PATH);
        $redirectQuery = (string) parse_url($redirect, PHP_URL_QUERY);

        $samePath = $currentPath !== '' && rtrim($currentPath, '/') === rtrim($redirectPath, '/');
        if (!$samePath) {
            return $params;
        }

        // If redirect points to the same URL (with/without query variations), drop it.
        if ($redirectQuery === '' || $redirectQuery === $currentQuery || str_contains($redirectQuery, 'returnTo=')) {
            $params['redirect'] = '';
        }

        return $params;
        }, rex_extension::EARLY);
    }

    if (!$ycomActive) {
        // 2. Hide restricted articles/categories in navigations via isPermitted()
        rex_extension::register('ART_IS_PERMITTED', static function (rex_extension_point $ep) {
        // If already false by another add-on, don't override to true
        if (!$ep->getSubject()) {
             return false;
        }

        $article = $ep->getParam('element');
        $auth = new Auth();
        $permissionManager = new PermissionManager();

        return $permissionManager->checkArticleAccess($auth->getUser(), $article->getId());
        });

        rex_extension::register('CAT_IS_PERMITTED', static function (rex_extension_point $ep) {
        if (!$ep->getSubject()) {
             return false;
        }

        $category = $ep->getParam('element');
        $auth = new Auth();
        $permissionManager = new PermissionManager();

        return $permissionManager->checkCategoryAccess($auth->getUser(), $category->getId());
        });
    }

    if (!$ycomActive) {
        // 3. Media access check via Media Manager (e.g. index.php?rex_media_file=...)
        rex_extension::register('MEDIA_MANAGER_BEFORE_SEND', static function (rex_extension_point $ep) {
            $mediaManager = $ep->getSubject();
            $media = $mediaManager->getMedia();
            if (!$media) {
                return;
            }

            // Only enforce restrictions for real mediapool files.
            // Custom Media Manager source paths (e.g. assets folders) must stay unaffected.
            if (rex_path::media($media->getMediaFilename()) !== $media->getMediaPath()) {
                return;
            }

            $filename = $media->getMediaFilename();
            if (!MediaGuard::hasAccess($filename)) {
                rex_response::cleanOutputBuffers();
                rex_response::setStatus(rex_response::HTTP_FORBIDDEN);
                rex_response::sendCacheControl();
                rex_response::sendContent('');
                exit;
            }
        }, rex_extension::EARLY);

        // To affect rex_media::isPermitted() if used in templates:
        rex_extension::register('MEDIA_IS_PERMITTED', static function (rex_extension_point $ep) {
            if (!$ep->getSubject()) {
                 return false;
            }
            $media = $ep->getParam('element');
            return MediaGuard::hasAccess($media->getFileName());
        });
    }

    if (!$ycomActive) {
        // 4. Visible control bar while admin impersonation is active.
        rex_extension::register('OUTPUT_FILTER', static function (rex_extension_point $ep): string {
        $subject = (string) $ep->getSubject();
        $auth = new Auth();

        if (!$auth->isImpersonated() || !$auth->isLoggedIn()) {
            return $subject;
        }

        $user = $auth->getUser();
        $displayName = trim($user->firstname . ' ' . $user->lastname);
        if ($displayName === '') {
            $displayName = $user->email;
        }

        $byName = $auth->getImpersonatedByName();
        $stopUrl = rex_url::backendController(array_merge([
            'page' => 'klxm_restricted/users',
            'func' => 'klxm_stop_impersonate',
        ], rex_csrf_token::factory('klxm_restricted_impersonate')->getUrlParams()));

        $bar = '<div class="alert alert-warning" style="margin:0;border-radius:0;text-align:center;z-index:10000;">'
            . 'Admin-Imitation aktiv: Ansicht als <strong>' . htmlspecialchars($displayName) . '</strong>'
            . ($byName !== '' ? ' (gestartet von ' . htmlspecialchars($byName) . ')' : '')
            . ' - <a href="' . htmlspecialchars($stopUrl) . '">Imitation beenden</a>'
            . '</div>';

        return $bar . $subject;
        }, rex_extension::LATE);
    }
}
