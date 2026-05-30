<?php

declare(strict_types=1);

namespace KLXM\Restricted\Media;

use rex;
use rex_addon;
use rex_dir;
use rex_file;
use rex_login;
use rex_path;
use rex_request;
use rex_response;
use rex_set_session;
use rex_session;
use rex_sql;
use rex_unset_session;
use ZipArchive;

class ShareService
{
    private static ?bool $supportsTokenPlainColumn = null;

    /**
     * @param string[] $filenames
     */
    public static function createShare(
        int $categoryId,
        array $filenames,
        ?string $title,
        bool $allowZip,
        ?string $password,
        ?string $expiresAt,
        ?int $maxDownloads,
        string $createdBy
    ): string {
        $token = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_media_share'));
        $sql->setValue('token_hash', $tokenHash);
        $sql->setValue('token_hint', substr($token, 0, 12));
        if (self::supportsTokenPlainColumn()) {
            $sql->setValue('token_plain', $token);
        }
        $sql->setValue('category_id', $categoryId);
        $sql->setValue('title', $title ?? '');
        $sql->setValue('media_files', (string) json_encode(array_values($filenames), JSON_UNESCAPED_UNICODE));
        $sql->setValue('allow_zip', $allowZip ? 1 : 0);
        $sql->setValue('password_hash', $password !== null && $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : '');
        $sql->setValue('expires_at', $expiresAt !== null && $expiresAt !== '' ? $expiresAt : null);
        $sql->setValue('max_downloads', $maxDownloads ?? null);
        $sql->setValue('download_count', 0);
        $sql->setValue('status', 1);
        $sql->setValue('created_by', $createdBy);
        $sql->setValue('createdate', rex_sql::datetime(time()));
        $sql->setValue('updatedate', rex_sql::datetime(time()));
        $sql->insert();

        return $token;
    }

    private static function supportsTokenPlainColumn(): bool
    {
        if (self::$supportsTokenPlainColumn !== null) {
            return self::$supportsTokenPlainColumn;
        }

        try {
            $rows = rex_sql::factory()->getArray(
                'SHOW COLUMNS FROM ' . rex::getTable('klxm_restricted_media_share') . ' LIKE ?',
                ['token_plain']
            );

            self::$supportsTokenPlainColumn = $rows !== [];
        } catch (\Throwable) {
            self::$supportsTokenPlainColumn = false;
        }

        return self::$supportsTokenPlainColumn;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getShares(): array
    {
        return rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('klxm_restricted_media_share') . ' ORDER BY id DESC'
        );
    }

    public static function deleteShare(int $shareId): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_media_share'));
        $sql->setWhere('id = ?', [$shareId]);
        $sql->delete();
        rex_unset_session('klxm_restricted_share_auth_' . $shareId);
    }

    /**
     * @return array<int, array{id:int, filename:string, title:string, category_id:int}>
     */
    public static function getMediaForCategory(int $categoryId): array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT id, filename, title, category_id FROM ' . rex::getTable('media') . ' WHERE category_id = ? ORDER BY filename ASC',
            [$categoryId]
        );

        $media = [];
        foreach ($rows as $row) {
            $media[] = [
                'id' => (int) $row['id'],
                'filename' => (string) $row['filename'],
                'title' => (string) ($row['title'] ?? ''),
                'category_id' => (int) $row['category_id'],
            ];
        }

        return $media;
    }

    public static function handleFrontendShareRequest(): bool
    {
        self::redirectEscapedAmpUrlIfNeeded();

        $token = trim(rex_request::get('klxm_share', 'string', ''));
        if ($token === '') {
            return false;
        }

        self::redirectToCanonicalUrlIfNeeded($token);

        $lang = self::resolveLang();
        $uiTheme = self::resolveTheme();

        rex_login::startSession();

        $share = self::findByToken($token);
        if ($share === null || (int) $share['status'] !== 1) {
            self::sendHtml(self::t('share_not_found', $lang), rex_response::HTTP_NOT_FOUND);
        }

        if (self::isExpired($share)) {
            self::sendHtml(self::t('share_expired', $lang), '410 Gone');
        }

        if (self::hasDownloadLimitReached($share)) {
            self::sendHtml(self::t('download_limit_reached', $lang), rex_response::HTTP_FORBIDDEN);
        }

        $isPasswordProtected = (string) ($share['password_hash'] ?? '') !== '';
        $sessionKey = 'klxm_restricted_share_auth_' . (int) $share['id'];
        $isUnlocked = rex_session($sessionKey, 'int', 0) === 1;

        $passwordError = '';
        if ($isPasswordProtected && !$isUnlocked) {
            $submittedPassword = rex_request::post('share_password', 'string', '');
            if ($submittedPassword !== '') {
                if (password_verify($submittedPassword, (string) $share['password_hash'])) {
                    rex_set_session($sessionKey, 1);
                    $isUnlocked = true;
                } else {
                    $passwordError = self::t('password_incorrect', $lang);
                }
            }
        }

        if ($isPasswordProtected && !$isUnlocked) {
            self::renderPasswordPage($share, $passwordError, $lang, $uiTheme, $token);
        }

        $downloadMode = rex_request::get('download', 'string', '');
        if ($downloadMode === 'file') {
            $filename = rex_request::get('file', 'string', '');
            self::downloadSingleFile($share, $filename, $lang);
        }

        if ($downloadMode === 'zip') {
            self::downloadZip($share, $lang);
        }

        self::renderShareOverview($share, $lang, $uiTheme, $token);
        return true;
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function renderPasswordPage(array $share, string $passwordError, string $lang, string $uiTheme, string $token): void
    {
        $title = (string) ($share['title'] ?? self::t('default_share_title', $lang));
        $branding = self::getBranding();
        $html = '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>' . htmlspecialchars($title) . '</title>';
        $html .= self::baseStyles($branding['accent']);
        $html .= '</head><body data-theme="' . htmlspecialchars($uiTheme) . '">';
        $html .= '<div class="share-bg"></div><main class="share-wrap">';
        $html .= self::renderTopBar($lang, $uiTheme, $token);
        $html .= self::renderBrandingHeader($branding);
        $html .= '<section class="share-card share-card-narrow">';
        $html .= '<h1>' . htmlspecialchars(self::t('password_page_title', $lang)) . '</h1>';
        $html .= '<p class="lead">' . htmlspecialchars(self::t('password_page_intro', $lang)) . '</p>';
        if ($passwordError !== '') {
            $html .= '<p class="error">' . htmlspecialchars($passwordError) . '</p>';
        }
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="klxm_lang" value="' . htmlspecialchars($lang) . '">';
        $html .= '<input type="hidden" name="klxm_theme" value="' . htmlspecialchars($uiTheme) . '">';
        $html .= '<label for="share_password">' . htmlspecialchars(self::t('password_label', $lang)) . '</label>';
        $html .= '<input id="share_password" type="password" name="share_password" required>';
        $html .= '<button type="submit">' . htmlspecialchars(self::t('open_share', $lang)) . '</button>';
        $html .= '</form></section></main></body></html>';

        self::sendHtml($html, rex_response::HTTP_OK, true);
    }

    private static function redirectToCanonicalUrlIfNeeded(string $token): void
    {
        $rawLang = trim(rex_request::get('amp;klxm_lang', 'string', ''));
        $rawTheme = trim(rex_request::get('amp;klxm_theme', 'string', ''));
        $hasCleanLang = trim(rex_request::get('klxm_lang', 'string', '')) !== '';
        $hasCleanTheme = trim(rex_request::get('klxm_theme', 'string', '')) !== '';

        if ($rawLang === '' && $rawTheme === '') {
            return;
        }

        if ($hasCleanLang || $hasCleanTheme) {
            return;
        }

        $params = [
            'klxm_share' => $token,
        ];

        $download = trim(rex_request::get('download', 'string', ''));
        if ($download !== '') {
            $params['download'] = $download;
        }

        $file = trim(rex_request::get('file', 'string', ''));
        if ($file !== '') {
            $params['file'] = $file;
        }

        if ($rawLang !== '') {
            $params['klxm_lang'] = $rawLang;
        }

        if ($rawTheme !== '') {
            $params['klxm_theme'] = $rawTheme;
        }

        rex_response::sendRedirect('?' . http_build_query($params));
    }

    private static function redirectEscapedAmpUrlIfNeeded(): void
    {
        $requestUri = (string) rex_request::server('REQUEST_URI', 'string', '');
        if ($requestUri === '') {
            return;
        }

        $normalized = str_ireplace(['&amp;', '&#038;'], '&', $requestUri);
        if ($normalized !== $requestUri) {
            rex_response::sendRedirect($normalized);
        }
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function renderShareOverview(array $share, string $lang, string $uiTheme, string $token): void
    {
        $title = (string) ($share['title'] ?? self::t('default_share_title', $lang));
        $files = self::getShareFileRows($share);
        $branding = self::getBranding();

        $html = '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>' . htmlspecialchars($title) . '</title>';
        $html .= self::baseStyles($branding['accent']);
        $html .= '</head><body data-theme="' . htmlspecialchars($uiTheme) . '">';
        $html .= '<div class="share-bg"></div><main class="share-wrap">';
        $html .= self::renderTopBar($lang, $uiTheme, $token);
        $html .= self::renderBrandingHeader($branding);
        $html .= '<section class="share-card">';
        $html .= '<h1>' . htmlspecialchars($title !== '' ? $title : self::t('default_share_title', $lang)) . '</h1>';

        if ($files === []) {
            $html .= '<p class="lead">' . htmlspecialchars(self::t('no_files_available', $lang)) . '</p>';
        } else {
            $html .= '<p class="lead">' . htmlspecialchars(self::t('available_files', $lang)) . '</p>';
            $html .= '<ul class="file-list">';
            foreach ($files as $file) {
                $name = (string) $file['filename'];
                $downloadLink = self::buildShareLink($token, [
                    'download' => 'file',
                    'file' => $name,
                    'klxm_lang' => $lang,
                    'klxm_theme' => $uiTheme,
                ]);
                $label = (string) $file['title'];
                $displayName = $label !== '' ? $label : $name;
                $html .= '<li><span>' . htmlspecialchars($displayName) . '<small>' . htmlspecialchars($name) . '</small></span>';
                $html .= '<a class="btn-link" href="' . htmlspecialchars($downloadLink) . '">' . htmlspecialchars(self::t('download_file', $lang)) . '</a></li>';
            }
            $html .= '</ul>';

            if ((int) ($share['allow_zip'] ?? 0) === 1) {
                $zipLink = self::buildShareLink($token, [
                    'download' => 'zip',
                    'klxm_lang' => $lang,
                    'klxm_theme' => $uiTheme,
                ]);
                $html .= '<div class="actions"><a class="btn-primary" href="' . htmlspecialchars($zipLink) . '">' . htmlspecialchars(self::t('download_zip', $lang)) . '</a></div>';
            }
        }

        $html .= '</section></main></body></html>';
        self::sendHtml($html, rex_response::HTTP_OK, true);
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function downloadSingleFile(array $share, string $filename, string $lang): void
    {
        if ($filename === '') {
            self::sendHtml(self::t('file_missing', $lang), rex_response::HTTP_BAD_REQUEST);
        }

        $allowedFiles = self::getShareFilenames($share);
        if (!in_array($filename, $allowedFiles, true)) {
            self::sendHtml(self::t('file_not_part_of_share', $lang), rex_response::HTTP_FORBIDDEN);
        }

        $path = rex_path::media($filename);
        if (!is_file($path)) {
            self::sendHtml(self::t('file_not_found', $lang), rex_response::HTTP_NOT_FOUND);
        }

        self::increaseDownloadCount((int) $share['id']);
        rex_response::sendFile($path, 'application/octet-stream', 'attachment', $filename);
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function downloadZip(array $share, string $lang): void
    {
        if ((int) ($share['allow_zip'] ?? 0) !== 1) {
            self::sendHtml(self::t('zip_disabled', $lang), rex_response::HTTP_FORBIDDEN);
        }

        $files = self::getShareFilenames($share);
        if ($files === []) {
            self::sendHtml(self::t('no_files_available', $lang), rex_response::HTTP_NOT_FOUND);
        }

        $zipDir = rex_path::addonCache('klxm_restricted', 'shares/');
        rex_dir::create($zipDir);

        $zipPath = $zipDir . 'share-' . (int) $share['id'] . '-' . bin2hex(random_bytes(8)) . '.zip';

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            self::sendHtml(self::t('zip_creation_failed', $lang), rex_response::HTTP_INTERNAL_ERROR);
        }

        $usedNames = [];
        foreach ($files as $filename) {
            $path = rex_path::media($filename);
            if (!is_file($path)) {
                continue;
            }

            $entryName = self::buildZipEntryName($filename, $usedNames);
            $zip->addFile($path, $entryName);
            $usedNames[] = $entryName;
        }

        $zip->close();

        register_shutdown_function(static function () use ($zipPath): void {
            rex_file::delete($zipPath);
        });

        self::increaseDownloadCount((int) $share['id']);
        rex_response::sendFile($zipPath, 'application/zip', 'attachment', 'share-' . (int) $share['id'] . '.zip');
    }

    /**
     * @param array<string, mixed> $share
     * @return array<int, array{filename:string, title:string}>
     */
    private static function getShareFileRows(array $share): array
    {
        $filenames = self::getShareFilenames($share);
        if ($filenames === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($filenames), '?'));
        $rows = rex_sql::factory()->getArray(
            'SELECT filename, title FROM ' . rex::getTable('media') . ' WHERE filename IN (' . $placeholders . ')',
            $filenames
        );

        $indexedRows = [];
        foreach ($rows as $row) {
            $indexedRows[(string) $row['filename']] = [
                'filename' => (string) $row['filename'],
                'title' => (string) ($row['title'] ?? ''),
            ];
        }

        $result = [];
        foreach ($filenames as $filename) {
            if (isset($indexedRows[$filename])) {
                $result[] = $indexedRows[$filename];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $share
     * @return string[]
     */
    private static function getShareFilenames(array $share): array
    {
        $raw = (string) ($share['media_files'] ?? '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $files = [];
        foreach ($decoded as $item) {
            if (is_string($item) && $item !== '') {
                $files[] = $item;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findByToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('klxm_restricted_media_share') . ' WHERE token_hash = ? LIMIT 1',
            [$tokenHash]
        );

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function isExpired(array $share): bool
    {
        $expiresAt = (string) ($share['expires_at'] ?? '');
        if ($expiresAt === '' || $expiresAt === '0000-00-00 00:00:00') {
            return false;
        }

        $timestamp = strtotime($expiresAt);
        if ($timestamp === false) {
            return false;
        }

        return $timestamp < time();
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function hasDownloadLimitReached(array $share): bool
    {
        $maxDownloads = (int) ($share['max_downloads'] ?? 0);
        if ($maxDownloads <= 0) {
            return false;
        }

        return (int) ($share['download_count'] ?? 0) >= $maxDownloads;
    }

    private static function increaseDownloadCount(int $shareId): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'UPDATE ' . rex::getTable('klxm_restricted_media_share')
            . ' SET download_count = download_count + 1, last_download = ?, updatedate = ? WHERE id = ?',
            [
                rex_sql::datetime(time()),
                rex_sql::datetime(time()),
                $shareId,
            ]
        );
    }

    /**
     * @param string[] $usedNames
     */
    private static function buildZipEntryName(string $filename, array $usedNames): string
    {
        $baseName = rex_path::basename($filename);
        if (!in_array($baseName, $usedNames, true)) {
            return $baseName;
        }

        $nameWithoutExt = pathinfo($baseName, PATHINFO_FILENAME);
        $ext = pathinfo($baseName, PATHINFO_EXTENSION);
        $i = 1;
        do {
            $candidate = $nameWithoutExt . '-' . $i;
            if ($ext !== '') {
                $candidate .= '.' . $ext;
            }
            $i++;
        } while (in_array($candidate, $usedNames, true));

        return $candidate;
    }

    private static function sendHtml(string $html, string $statusCode, bool $isFullHtml = false): never
    {
        rex_response::cleanOutputBuffers();
        rex_response::setStatus($statusCode);
        rex_response::sendCacheControl('no-store, no-cache, must-revalidate');

        if ($isFullHtml) {
            rex_response::sendContent($html, 'text/html; charset=utf-8');
            exit;
        }

        rex_response::sendContent('<!doctype html><meta charset="utf-8"><p>' . htmlspecialchars($html) . '</p>', 'text/html; charset=utf-8');
        exit;
    }

    private static function resolveLang(): string
    {
        $lang = strtolower(trim(rex_request::get('klxm_lang', 'string', '')));
        if ($lang === '') {
            $lang = strtolower(trim(rex_request::get('amp;klxm_lang', 'string', 'de')));
        }
        if ($lang !== 'en' && $lang !== 'de') {
            return 'de';
        }

        return $lang;
    }

    private static function resolveTheme(): string
    {
        $theme = strtolower(trim(rex_request::get('klxm_theme', 'string', '')));
        if ($theme === '') {
            $theme = strtolower(trim(rex_request::get('amp;klxm_theme', 'string', 'auto')));
        }
        if ($theme !== 'auto' && $theme !== 'light' && $theme !== 'dark') {
            return 'auto';
        }

        return $theme;
    }

    /**
     * @param array<string, string> $params
     */
    private static function buildShareLink(string $token, array $params = []): string
    {
        $query = array_merge(['klxm_share' => $token], $params);
        return '?' . http_build_query($query);
    }

    private static function renderTopBar(string $lang, string $uiTheme, string $token): string
    {
        $deLink = self::buildShareLink($token, ['klxm_lang' => 'de', 'klxm_theme' => $uiTheme]);
        $enLink = self::buildShareLink($token, ['klxm_lang' => 'en', 'klxm_theme' => $uiTheme]);
        $autoThemeLink = self::buildShareLink($token, ['klxm_lang' => $lang, 'klxm_theme' => 'auto']);
        $lightThemeLink = self::buildShareLink($token, ['klxm_lang' => $lang, 'klxm_theme' => 'light']);
        $darkThemeLink = self::buildShareLink($token, ['klxm_lang' => $lang, 'klxm_theme' => 'dark']);

        $html = '<div class="share-topbar">';
        $html .= '<div class="share-pill">';
        $html .= '<span>' . htmlspecialchars(self::t('language', $lang)) . '</span>';
        $html .= '<a' . ($lang === 'de' ? ' class="is-active"' : '') . ' href="' . htmlspecialchars($deLink) . '">DE</a>';
        $html .= '<a' . ($lang === 'en' ? ' class="is-active"' : '') . ' href="' . htmlspecialchars($enLink) . '">EN</a>';
        $html .= '</div>';

        $html .= '<div class="share-pill">';
        $html .= '<span>' . htmlspecialchars(self::t('theme', $lang)) . '</span>';
        $html .= '<a' . ($uiTheme === 'auto' ? ' class="is-active"' : '') . ' href="' . htmlspecialchars($autoThemeLink) . '">Auto</a>';
        $html .= '<a' . ($uiTheme === 'light' ? ' class="is-active"' : '') . ' href="' . htmlspecialchars($lightThemeLink) . '">Light</a>';
        $html .= '<a' . ($uiTheme === 'dark' ? ' class="is-active"' : '') . ' href="' . htmlspecialchars($darkThemeLink) . '">Dark</a>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private static function baseStyles(string $accent): string
    {
        return '<style>'
            . ':root{color-scheme:light dark;--bg:#f4f7fb;--bg2:#e6edf7;--card:#ffffff;--text:#102238;--muted:#5f738c;--line:#d3deea;--primary:' . $accent . ';--primary-2:' . $accent . ';--ok:#0c8b57;--danger:#b42333;}'
            . 'body[data-theme="dark"]{--bg:#0d1520;--bg2:#1a2737;--card:#122033;--text:#e8f1fb;--muted:#9ab1c9;--line:#2f4760;--primary:' . $accent . ';--primary-2:' . $accent . ';--ok:#22b573;--danger:#f05a68;}'
            . 'body[data-theme="light"]{--bg:#f4f7fb;--bg2:#e6edf7;--card:#ffffff;--text:#102238;--muted:#5f738c;--line:#d3deea;--primary:' . $accent . ';--primary-2:' . $accent . ';--ok:#0c8b57;--danger:#b42333;}'
            . '@media (prefers-color-scheme: dark){body[data-theme="auto"]{--bg:#0d1520;--bg2:#1a2737;--card:#122033;--text:#e8f1fb;--muted:#9ab1c9;--line:#2f4760;--primary:' . $accent . ';--primary-2:' . $accent . ';--ok:#22b573;--danger:#f05a68;}}'
            . '*{box-sizing:border-box}'
            . 'body{margin:0;padding:0;font-family:"Segoe UI", "SF Pro Text", "Helvetica Neue", sans-serif;color:var(--text);background:linear-gradient(140deg,var(--bg),var(--bg2));min-height:100vh}'
            . '.share-bg{position:fixed;inset:0;background:radial-gradient(circle at 15% 10%,rgba(15,110,184,.16),transparent 42%),radial-gradient(circle at 85% 80%,rgba(12,139,87,.16),transparent 35%);pointer-events:none}'
            . '.share-wrap{position:relative;z-index:1;max-width:960px;margin:0 auto;padding:24px 18px 40px}'
            . '.share-topbar{display:flex;gap:12px;justify-content:flex-end;flex-wrap:wrap;margin-bottom:14px}'
            . '.share-brand{margin-bottom:14px}'
            . '.share-brand h2{margin:0;font-size:1rem;color:var(--primary);letter-spacing:.04em;text-transform:uppercase}'
            . '.share-brand p{margin:4px 0 0;color:var(--muted)}'
            . '.share-pill{display:flex;gap:6px;align-items:center;padding:6px 8px;border:1px solid var(--line);background:color-mix(in srgb,var(--card) 88%,transparent);border-radius:999px}'
            . '.share-pill span{font-size:12px;color:var(--muted);padding:0 6px}'
            . '.share-pill a{font-size:12px;text-decoration:none;color:var(--muted);padding:4px 8px;border-radius:999px}'
            . '.share-pill a.is-active,.share-pill a:hover{background:var(--primary);color:#fff}'
            . '.share-card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:28px;box-shadow:0 12px 42px rgba(0,0,0,.12)}'
            . '.share-card-narrow{max-width:640px;margin:0 auto}'
            . 'h1{margin:0 0 10px;font-size:clamp(1.4rem,2.8vw,2rem);line-height:1.2}'
            . '.lead{margin:0 0 16px;color:var(--muted);font-size:1.02rem}'
            . '.error{background:color-mix(in srgb,var(--danger) 14%,transparent);color:var(--danger);border:1px solid color-mix(in srgb,var(--danger) 44%,transparent);border-radius:10px;padding:10px 12px}'
            . 'form label{display:block;margin-bottom:8px;font-weight:600}'
            . 'input[type="password"]{width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:transparent;color:var(--text)}'
            . 'button,.btn-primary{display:inline-flex;align-items:center;justify-content:center;margin-top:14px;padding:11px 14px;border-radius:10px;border:0;background:linear-gradient(180deg,var(--primary),var(--primary-2));color:#fff;font-weight:600;text-decoration:none;cursor:pointer}'
            . '.actions{margin-top:14px}'
            . '.file-list{margin:0;padding:0;list-style:none;display:grid;gap:10px}'
            . '.file-list li{display:flex;justify-content:space-between;align-items:center;gap:10px;border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:color-mix(in srgb,var(--card) 92%,transparent)}'
            . '.file-list li span{display:flex;flex-direction:column;gap:2px;min-width:0;word-break:break-all}'
            . '.file-list li small{color:var(--muted)}'
            . '.btn-link{display:inline-flex;align-items:center;justify-content:center;padding:8px 11px;border-radius:10px;border:1px solid var(--line);text-decoration:none;color:var(--text);font-weight:600;white-space:nowrap}'
            . '.btn-link:hover{border-color:var(--primary);color:var(--primary)}'
            . '@media (max-width:700px){.share-wrap{padding:14px 10px 24px}.share-card{padding:18px}.file-list li{flex-direction:column;align-items:flex-start}.btn-link,.btn-primary,button{width:100%}}'
            . '</style>';
    }

    /**
     * @return array{title:string, subtitle:string, accent:string}
     */
    private static function getBranding(): array
    {
        $addon = rex_addon::get('klxm_restricted');
        $title = trim((string) $addon->getConfig('share_brand_title', ''));
        $subtitle = trim((string) $addon->getConfig('share_brand_subtitle', ''));
        $accent = trim((string) $addon->getConfig('share_brand_accent', '#0f6eb8'));

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $accent = '#0f6eb8';
        }

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'accent' => $accent,
        ];
    }

    /**
     * @param array{title:string, subtitle:string, accent:string} $branding
     */
    private static function renderBrandingHeader(array $branding): string
    {
        if ($branding['title'] === '' && $branding['subtitle'] === '') {
            return '';
        }

        $html = '<div class="share-brand">';
        if ($branding['title'] !== '') {
            $html .= '<h2>' . htmlspecialchars($branding['title']) . '</h2>';
        }
        if ($branding['subtitle'] !== '') {
            $html .= '<p>' . htmlspecialchars($branding['subtitle']) . '</p>';
        }
        $html .= '</div>';

        return $html;
    }

    private static function t(string $key, string $lang): string
    {
        $dict = [
            'de' => [
                'default_share_title' => 'Dateifreigabe',
                'share_not_found' => 'Freigabe nicht gefunden.',
                'share_expired' => 'Diese Freigabe ist abgelaufen.',
                'download_limit_reached' => 'Das Download-Limit wurde erreicht.',
                'password_incorrect' => 'Passwort ist nicht korrekt.',
                'password_page_title' => 'Passwortgeschuetzte Freigabe',
                'password_page_intro' => 'Bitte Passwort eingeben, um auf die Dateien zuzugreifen.',
                'password_label' => 'Passwort',
                'open_share' => 'Freigabe oeffnen',
                'available_files' => 'Verfuegbare Dateien',
                'download_file' => 'Datei laden',
                'download_zip' => 'Als ZIP herunterladen',
                'no_files_available' => 'Keine Dateien mehr verfuegbar.',
                'file_missing' => 'Datei fehlt.',
                'file_not_part_of_share' => 'Datei ist nicht Teil dieser Freigabe.',
                'file_not_found' => 'Datei existiert nicht mehr.',
                'zip_disabled' => 'ZIP-Download ist fuer diese Freigabe deaktiviert.',
                'zip_creation_failed' => 'ZIP konnte nicht erstellt werden.',
                'language' => 'Sprache',
                'theme' => 'Theme',
            ],
            'en' => [
                'default_share_title' => 'File Share',
                'share_not_found' => 'Share not found.',
                'share_expired' => 'This share has expired.',
                'download_limit_reached' => 'The download limit has been reached.',
                'password_incorrect' => 'Incorrect password.',
                'password_page_title' => 'Password Protected Share',
                'password_page_intro' => 'Enter the password to access the files.',
                'password_label' => 'Password',
                'open_share' => 'Open share',
                'available_files' => 'Available files',
                'download_file' => 'Download file',
                'download_zip' => 'Download as ZIP',
                'no_files_available' => 'No files are currently available.',
                'file_missing' => 'File parameter is missing.',
                'file_not_part_of_share' => 'The file is not part of this share.',
                'file_not_found' => 'The file no longer exists.',
                'zip_disabled' => 'ZIP download is disabled for this share.',
                'zip_creation_failed' => 'ZIP archive could not be created.',
                'language' => 'Language',
                'theme' => 'Theme',
            ],
        ];

        return $dict[$lang][$key] ?? $dict['de'][$key] ?? $key;
    }
}
