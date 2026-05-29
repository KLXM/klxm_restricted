<?php

declare(strict_types=1);

namespace KLXM\Restricted\Frontend;

use rex;
use rex_addon;
use rex_login;
use rex_path;
use rex_request;
use rex_response;
use rex_set_session;
use rex_session;
use rex_sql;
use rex_unset_session;

class PastebinService
{
    /**
     * @param string[] $attachments
     */
    public static function createPaste(
        string $title,
        string $secretContent,
        array $attachments,
        ?string $accessPassword,
        ?string $expiresAt,
        string $createdBy
    ): string {
        $token = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_pastebin'));
        $sql->setValue('token_hash', $tokenHash);
        $sql->setValue('token_hint', substr($token, 0, 12));
        $sql->setValue('title', $title);
        $sql->setValue('secret_content', $secretContent);
        $sql->setValue('attachment_files', (string) json_encode(array_values($attachments), JSON_UNESCAPED_UNICODE));
        $sql->setValue('password_hash', $accessPassword !== null && $accessPassword !== '' ? password_hash($accessPassword, PASSWORD_DEFAULT) : '');
        $sql->setValue('expires_at', $expiresAt ?? '');
        $sql->setValue('status', 1);
        $sql->setValue('view_count', 0);
        $sql->setValue('created_by', $createdBy);
        $sql->setValue('createdate', rex_sql::datetime(time()));
        $sql->setValue('updatedate', rex_sql::datetime(time()));
        $sql->insert();

        return $token;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getEntries(): array
    {
        return rex_sql::factory()->getArray(
            'SELECT id, token_hint, title, expires_at, status, view_count, created_by, createdate, destroyedate'
            . ' FROM ' . rex::getTable('klxm_restricted_pastebin')
            . ' ORDER BY id DESC'
        );
    }

    public static function deleteEntry(int $id): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_pastebin'));
        $sql->setWhere('id = ?', [$id]);
        $sql->delete();
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

    public static function handleFrontendRequest(): bool
    {
        self::redirectEscapedAmpUrlIfNeeded();

        $token = trim(rex_request::get('klxm_paste', 'string', ''));
        if ($token === '') {
            return false;
        }

        self::redirectToCanonicalUrlIfNeeded($token);

        $lang = self::resolveLang();
        $uiTheme = self::resolveTheme();
        $tokenHash = hash('sha256', $token);
        $sessionKey = 'klxm_pastebin_payload_' . $tokenHash;

        rex_login::startSession();

        $payload = rex_session($sessionKey, 'array', []);
        if (rex_request::post('clear_session', 'int', 0) === 1) {
            rex_unset_session($sessionKey);
            self::sendHtml(self::t('session_cleared', $lang), rex_response::HTTP_OK);
        }

        $downloadIdx = rex_request::get('download_idx', 'int', -1);
        if ($downloadIdx >= 0 && $payload !== []) {
            self::downloadAttachmentFromSession($payload, $downloadIdx, $lang);
        }

        $entry = self::findActiveByTokenHash($tokenHash);
        if ($entry !== null && self::isExpired($entry)) {
            self::consumeExpiredEntry((int) $entry['id']);
            $entry = null;
        }

        if ($entry === null && $payload === []) {
            self::sendHtml(self::t('paste_not_found', $lang), rex_response::HTTP_NOT_FOUND);
        }

        if ($entry !== null) {
            $passwordError = '';
            $isPasswordProtected = (string) ($entry['password_hash'] ?? '') !== '';
            if ($isPasswordProtected) {
                $submitted = rex_request::post('access_password', 'string', '');
                if ($submitted !== '' && !password_verify($submitted, (string) $entry['password_hash'])) {
                    $passwordError = self::t('password_incorrect', $lang);
                }
            }

            if ($passwordError !== '') {
                self::renderGatePage($entry, $passwordError, $lang, $uiTheme, $token);
            }

            if (rex_request::post('reveal_now', 'int', 0) === 1) {
                $revealPayload = [
                    'title' => (string) ($entry['title'] ?? ''),
                    'secret_content' => (string) ($entry['secret_content'] ?? ''),
                    'attachments' => self::decodeAttachmentFiles((string) ($entry['attachment_files'] ?? '[]')),
                    'createdate' => (string) ($entry['createdate'] ?? ''),
                    'destroyed_at' => date('Y-m-d H:i:s'),
                ];

                rex_set_session($sessionKey, $revealPayload);
                self::consumeEntry((int) $entry['id']);
                self::renderRevealPage($revealPayload, $lang, $uiTheme, $token);
            }

            self::renderGatePage($entry, '', $lang, $uiTheme, $token);
        }

        self::renderRevealPage($payload, $lang, $uiTheme, $token);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function downloadAttachmentFromSession(array $payload, int $downloadIdx, string $lang): void
    {
        $attachments = $payload['attachments'] ?? [];
        if (!is_array($attachments) || !isset($attachments[$downloadIdx]) || !is_string($attachments[$downloadIdx])) {
            self::sendHtml(self::t('attachment_not_found', $lang), rex_response::HTTP_NOT_FOUND);
        }

        $filename = $attachments[$downloadIdx];
        $path = rex_path::media($filename);
        if (!is_file($path)) {
            self::sendHtml(self::t('attachment_missing_file', $lang), rex_response::HTTP_NOT_FOUND);
        }

        rex_response::sendFile($path, 'application/octet-stream', 'attachment', $filename);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function renderGatePage(array $entry, string $passwordError, string $lang, string $uiTheme, string $token): never
    {
        $title = (string) ($entry['title'] ?? self::t('default_title', $lang));
        $requiresPassword = (string) ($entry['password_hash'] ?? '') !== '';
        $branding = self::getBranding();

        $html = '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>' . htmlspecialchars($title) . '</title>';
        $html .= self::baseStyles($branding['accent']);
        $html .= '</head><body data-theme="' . htmlspecialchars($uiTheme) . '">';
        $html .= '<div class="pb-bg"></div><main class="pb-wrap">';
        $html .= self::renderTopBar($lang, $uiTheme, $token);
        $html .= self::renderBrandingHeader($branding);
        $html .= '<section class="pb-card pb-card-narrow">';
        $html .= '<h1>' . htmlspecialchars($title !== '' ? $title : self::t('default_title', $lang)) . '</h1>';
        $html .= '<p class="lead">' . htmlspecialchars(self::t('gate_intro', $lang)) . '</p>';

        if ($passwordError !== '') {
            $html .= '<p class="error">' . htmlspecialchars($passwordError) . '</p>';
        }

        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="klxm_lang" value="' . htmlspecialchars($lang) . '">';
        $html .= '<input type="hidden" name="klxm_theme" value="' . htmlspecialchars($uiTheme) . '">';
        if ($requiresPassword) {
            $html .= '<label for="access_password">' . htmlspecialchars(self::t('password_label', $lang)) . '</label>';
            $html .= '<input id="access_password" type="password" name="access_password" required>';
        }
        $html .= '<button type="submit" name="reveal_now" value="1">' . htmlspecialchars(self::t('reveal_and_destroy', $lang)) . '</button>';
        $html .= '</form>';
        $html .= '</section></main></body></html>';

        self::sendHtml($html, rex_response::HTTP_OK, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function renderRevealPage(array $payload, string $lang, string $uiTheme, string $token): never
    {
        $title = (string) ($payload['title'] ?? self::t('default_title', $lang));
        $secret = (string) ($payload['secret_content'] ?? '');
        $branding = self::getBranding();
        $attachments = $payload['attachments'] ?? [];
        if (!is_array($attachments)) {
            $attachments = [];
        }

        $html = '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>' . htmlspecialchars($title) . '</title>';
        $html .= self::baseStyles($branding['accent']);
        $html .= '</head><body data-theme="' . htmlspecialchars($uiTheme) . '">';
        $html .= '<div class="pb-bg"></div><main class="pb-wrap">';
        $html .= self::renderTopBar($lang, $uiTheme, $token);
        $html .= self::renderBrandingHeader($branding);
        $html .= '<section class="pb-card">';
        $html .= '<h1>' . htmlspecialchars($title !== '' ? $title : self::t('default_title', $lang)) . '</h1>';
        $html .= '<p class="lead success">' . htmlspecialchars(self::t('destroyed_notice', $lang)) . '</p>';
        $html .= '<pre class="secret-block">' . htmlspecialchars($secret) . '</pre>';

        if ($attachments !== []) {
            $html .= '<h2>' . htmlspecialchars(self::t('attachments', $lang)) . '</h2>';
            $html .= '<ul class="file-list">';
            foreach ($attachments as $idx => $filename) {
                if (!is_string($filename)) {
                    continue;
                }
                $link = self::buildPasteLink($token, [
                    'download_idx' => (string) $idx,
                    'klxm_lang' => $lang,
                    'klxm_theme' => $uiTheme,
                ]);
                $html .= '<li><span>' . htmlspecialchars($filename) . '</span><a class="btn-link" href="' . htmlspecialchars($link) . '">' . htmlspecialchars(self::t('download_file', $lang)) . '</a></li>';
            }
            $html .= '</ul>';
        }

        $html .= '<form method="post" style="margin-top:16px;">';
        $html .= '<input type="hidden" name="klxm_lang" value="' . htmlspecialchars($lang) . '">';
        $html .= '<input type="hidden" name="klxm_theme" value="' . htmlspecialchars($uiTheme) . '">';
        $html .= '<button type="submit" name="clear_session" value="1">' . htmlspecialchars(self::t('clear_now', $lang)) . '</button>';
        $html .= '</form>';

        $html .= '</section></main></body></html>';

        self::sendHtml($html, rex_response::HTTP_OK, true);
    }

    private static function consumeEntry(int $id): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'UPDATE ' . rex::getTable('klxm_restricted_pastebin') . ' '
            . 'SET status = 0, view_count = view_count + 1, destroyedate = ?, updatedate = ?, secret_content = ?, attachment_files = ?, password_hash = ? '
            . 'WHERE id = ?',
            [
                rex_sql::datetime(time()),
                rex_sql::datetime(time()),
                '',
                '[]',
                '',
                $id,
            ]
        );
    }

    private static function consumeExpiredEntry(int $id): void
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'UPDATE ' . rex::getTable('klxm_restricted_pastebin') . ' '
            . 'SET status = 0, destroyedate = ?, updatedate = ?, secret_content = ?, attachment_files = ?, password_hash = ? '
            . 'WHERE id = ?',
            [
                rex_sql::datetime(time()),
                rex_sql::datetime(time()),
                '',
                '[]',
                '',
                $id,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findActiveByTokenHash(string $tokenHash): ?array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('klxm_restricted_pastebin') . ' WHERE token_hash = ? AND status = 1 LIMIT 1',
            [$tokenHash]
        );

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function isExpired(array $entry): bool
    {
        $expiresAt = (string) ($entry['expires_at'] ?? '');
        if ($expiresAt === '') {
            return false;
        }

        $ts = strtotime($expiresAt);
        if ($ts === false) {
            return false;
        }

        return $ts < time();
    }

    /**
     * @return string[]
     */
    private static function decodeAttachmentFiles(string $raw): array
    {
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
    private static function buildPasteLink(string $token, array $params = []): string
    {
        return '?' . http_build_query(array_merge(['klxm_paste' => $token], $params));
    }

    private static function renderTopBar(string $lang, string $uiTheme, string $token): string
    {
        $deLink = self::buildPasteLink($token, ['klxm_lang' => 'de', 'klxm_theme' => $uiTheme]);
        $enLink = self::buildPasteLink($token, ['klxm_lang' => 'en', 'klxm_theme' => $uiTheme]);
        $autoThemeLink = self::buildPasteLink($token, ['klxm_lang' => $lang, 'klxm_theme' => 'auto']);
        $lightThemeLink = self::buildPasteLink($token, ['klxm_lang' => $lang, 'klxm_theme' => 'light']);
        $darkThemeLink = self::buildPasteLink($token, ['klxm_lang' => $lang, 'klxm_theme' => 'dark']);

        $html = '<div class="pb-topbar">';
        $html .= '<div class="pb-pill"><span>' . htmlspecialchars(self::t('language', $lang)) . '</span>';
        $html .= '<a' . ($lang === 'de' ? ' class="is-active"' : '') . ' href="' . htmlspecialchars($deLink) . '">DE</a>';
        $html .= '<a' . ($lang === 'en' ? ' class="is-active"' : '') . ' href="' . htmlspecialchars($enLink) . '">EN</a></div>';
        $html .= '<div class="pb-pill"><span>' . htmlspecialchars(self::t('theme', $lang)) . '</span>';
        $html .= '<a' . ($uiTheme === 'auto' ? ' class="is-active"' : '') . ' href="' . htmlspecialchars($autoThemeLink) . '">Auto</a>';
        $html .= '<a' . ($uiTheme === 'light' ? ' class="is-active"' : '') . ' href="' . htmlspecialchars($lightThemeLink) . '">Light</a>';
        $html .= '<a' . ($uiTheme === 'dark' ? ' class="is-active"' : '') . ' href="' . htmlspecialchars($darkThemeLink) . '">Dark</a></div>';
        $html .= '</div>';

        return $html;
    }

    private static function baseStyles(string $accent): string
    {
        return '<style>'
            . ':root{color-scheme:light dark;--bg:#f4f7fb;--bg2:#e6edf7;--card:#fff;--text:#102238;--muted:#5f738c;--line:#d3deea;--primary:' . $accent . ';--primary-2:' . $accent . ';--ok:#0c8b57;--danger:#b42333;}'
            . 'body[data-theme="dark"]{--bg:#0d1520;--bg2:#1a2737;--card:#122033;--text:#e8f1fb;--muted:#9ab1c9;--line:#2f4760;--primary:' . $accent . ';--primary-2:' . $accent . ';--ok:#22b573;--danger:#f05a68;}'
            . 'body[data-theme="light"]{--bg:#f4f7fb;--bg2:#e6edf7;--card:#fff;--text:#102238;--muted:#5f738c;--line:#d3deea;--primary:' . $accent . ';--primary-2:' . $accent . ';--ok:#0c8b57;--danger:#b42333;}'
            . '@media (prefers-color-scheme: dark){body[data-theme="auto"]{--bg:#0d1520;--bg2:#1a2737;--card:#122033;--text:#e8f1fb;--muted:#9ab1c9;--line:#2f4760;--primary:' . $accent . ';--primary-2:' . $accent . ';--ok:#22b573;--danger:#f05a68;}}'
            . '*{box-sizing:border-box} body{margin:0;font-family:"Segoe UI","SF Pro Text","Helvetica Neue",sans-serif;color:var(--text);background:linear-gradient(145deg,var(--bg),var(--bg2));min-height:100vh}'
            . '.pb-bg{position:fixed;inset:0;background:radial-gradient(circle at 20% 12%,rgba(15,110,184,.16),transparent 42%),radial-gradient(circle at 82% 84%,rgba(12,139,87,.16),transparent 36%);pointer-events:none}'
            . '.pb-wrap{position:relative;z-index:1;max-width:980px;margin:0 auto;padding:24px 18px 40px}'
            . '.pb-topbar{display:flex;gap:12px;justify-content:flex-end;flex-wrap:wrap;margin-bottom:14px}'
            . '.pb-brand{margin-bottom:14px}'
            . '.pb-brand h2{margin:0;font-size:1rem;color:var(--primary);letter-spacing:.04em;text-transform:uppercase}'
            . '.pb-brand p{margin:4px 0 0;color:var(--muted)}'
            . '.pb-pill{display:flex;gap:6px;align-items:center;padding:6px 8px;border:1px solid var(--line);background:color-mix(in srgb,var(--card) 88%,transparent);border-radius:999px}'
            . '.pb-pill span{font-size:12px;color:var(--muted);padding:0 6px}.pb-pill a{font-size:12px;text-decoration:none;color:var(--muted);padding:4px 8px;border-radius:999px}'
            . '.pb-pill a.is-active,.pb-pill a:hover{background:var(--primary);color:#fff}'
            . '.pb-card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:28px;box-shadow:0 12px 42px rgba(0,0,0,.12)}'
            . '.pb-card-narrow{max-width:640px;margin:0 auto}'
            . 'h1{margin:0 0 10px;font-size:clamp(1.35rem,2.8vw,2rem)} h2{margin:20px 0 10px;font-size:1.1rem}'
            . '.lead{margin:0 0 16px;color:var(--muted)} .success{color:var(--ok)} .error{background:color-mix(in srgb,var(--danger) 14%,transparent);color:var(--danger);border:1px solid color-mix(in srgb,var(--danger) 44%,transparent);border-radius:10px;padding:10px 12px}'
            . 'label{display:block;margin-bottom:8px;font-weight:600} input[type="password"]{width:100%;padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:transparent;color:var(--text)}'
            . 'button{display:inline-flex;align-items:center;justify-content:center;margin-top:14px;padding:11px 14px;border-radius:10px;border:0;background:linear-gradient(180deg,var(--primary),var(--primary-2));color:#fff;font-weight:600;cursor:pointer}'
            . '.secret-block{white-space:pre-wrap;word-break:break-word;background:color-mix(in srgb,var(--card) 92%,transparent);border:1px dashed var(--line);border-radius:12px;padding:14px;font-family:"JetBrains Mono","SFMono-Regular",Menlo,monospace;font-size:.93rem;line-height:1.45}'
            . '.file-list{margin:0;padding:0;list-style:none;display:grid;gap:10px}.file-list li{display:flex;justify-content:space-between;align-items:center;gap:10px;border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:color-mix(in srgb,var(--card) 92%,transparent)}'
            . '.btn-link{display:inline-flex;align-items:center;justify-content:center;padding:8px 11px;border-radius:10px;border:1px solid var(--line);text-decoration:none;color:var(--text);font-weight:600;white-space:nowrap}.btn-link:hover{border-color:var(--primary);color:var(--primary)}'
            . '@media (max-width:700px){.pb-wrap{padding:14px 10px 24px}.pb-card{padding:18px}.file-list li{flex-direction:column;align-items:flex-start}.btn-link,button{width:100%}}'
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

        $html = '<div class="pb-brand">';
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
                'default_title' => 'Sicherer Pastebin-Eintrag',
                'gate_intro' => 'Dieser Eintrag kann nur ein einziges Mal abgerufen werden und wird danach vernichtet.',
                'password_label' => 'Passwort',
                'reveal_and_destroy' => 'Jetzt abrufen und vernichten',
                'destroyed_notice' => 'Der Eintrag wurde jetzt aus der Datenbank entfernt.',
                'attachments' => 'Anhaenge',
                'download_file' => 'Datei laden',
                'clear_now' => 'Jetzt auch aus dieser Sitzung entfernen',
                'session_cleared' => 'Sitzungsdaten wurden geloescht.',
                'paste_not_found' => 'Eintrag nicht gefunden oder bereits vernichtet.',
                'password_incorrect' => 'Passwort ist nicht korrekt.',
                'attachment_not_found' => 'Anhang nicht gefunden.',
                'attachment_missing_file' => 'Anhang existiert nicht mehr.',
                'language' => 'Sprache',
                'theme' => 'Theme',
            ],
            'en' => [
                'default_title' => 'Secure Pastebin Entry',
                'gate_intro' => 'This entry can be retrieved once and will be destroyed afterwards.',
                'password_label' => 'Password',
                'reveal_and_destroy' => 'Reveal and destroy now',
                'destroyed_notice' => 'The entry has now been removed from the database.',
                'attachments' => 'Attachments',
                'download_file' => 'Download file',
                'clear_now' => 'Remove from this session now',
                'session_cleared' => 'Session data has been cleared.',
                'paste_not_found' => 'Entry not found or already destroyed.',
                'password_incorrect' => 'Incorrect password.',
                'attachment_not_found' => 'Attachment not found.',
                'attachment_missing_file' => 'Attachment file no longer exists.',
                'language' => 'Language',
                'theme' => 'Theme',
            ],
        ];

        return $dict[$lang][$key] ?? $dict['de'][$key] ?? $key;
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
            'klxm_paste' => $token,
        ];

        $downloadIdx = rex_request::get('download_idx', 'int', -1);
        if ($downloadIdx >= 0) {
            $params['download_idx'] = (string) $downloadIdx;
        }

        if ($rawLang !== '') {
            $params['klxm_lang'] = $rawLang;
        }

        if ($rawTheme !== '') {
            $params['klxm_theme'] = $rawTheme;
        }

        rex_response::sendRedirect('?' . http_build_query($params));
    }
}
