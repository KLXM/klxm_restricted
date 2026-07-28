<?php

declare(strict_types=1);

namespace KLXM\Restricted\Media;

use KLXM\Restricted\Auth;
use rex;
use rex_addon;
use rex_article;
use rex_backend_login;
use rex_clang;
use rex_csrf_token;
use rex_dir;
use rex_file;
use rex_login;
use rex_mailer;
use rex_media_category;
use rex_path;
use rex_request;
use rex_response;
use rex_set_session;
use rex_session;
use rex_sql;
use rex_string;
use rex_unset_session;
use rex_url;
use ZipArchive;

class BoardShareService
{
    private const REQUEST_GUARD_MIN_SECONDS = 3;
    private const REQUEST_GUARD_MAX_SECONDS = 7200;
    private const REQUEST_RESEND_COOLDOWN_SECONDS = 120;
    private const REQUEST_EXISTING_EMAIL_COOLDOWN_SECONDS = 180;

    private static ?string $mediaDescriptionSelect = null;
    /** @var array<string, string|null> */
    private static array $pdfThumbnailPathCache = [];
    /** @var array<int, string> */
    private static array $mediaCategoryNameCache = [];

    /**
     * @param string[] $manualFiles
     * @param array<string, string> $manualGroups
     */
    public static function createShare(
        string $shareMode,
        int $articleId,
        string $sourceMode,
        int $mediaCategoryId,
        array $manualFiles,
        array $manualGroups,
        array $categorizedGroups,
        bool $requestEnabled,
        int $requestValidDays,
        array $requestFields,
        ?string $requestIntroText,
        ?string $title,
        ?string $description,
        bool $allowZip,
        ?string $password,
        ?string $expiresAt,
        ?int $maxDownloads,
        string $createdBy
    ): string {
        $token = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_file_share'));
        $sql->setValue('token_hash', $tokenHash);
        $sql->setValue('token_plain', $token);
        $sql->setValue('token_hint', substr($token, 0, 12));
        $sql->setValue('share_mode', $shareMode);
        $sql->setValue('article_id', $articleId);
        $sql->setValue('source_mode', $sourceMode);
        $sql->setValue('media_category_id', $mediaCategoryId > 0 ? $mediaCategoryId : null);
        $sql->setValue('grouped_files', self::buildGroupedFilesPayload($sourceMode, $manualFiles, $manualGroups, $categorizedGroups));
        $sql->setValue('request_enabled', $requestEnabled ? 1 : 0);
        $sql->setValue('request_valid_days', max(1, $requestValidDays));
        $sql->setValue('request_form_json', self::encodeRequestFields($requestFields));
        $sql->setValue('request_intro_text', $requestIntroText ?? '');
        $sql->setValue('title', $title ?? '');
        $sql->setValue('description', $description ?? '');
        $sql->setValue('allow_zip', $allowZip ? 1 : 0);
        $sql->setValue('password_hash', $password !== null && $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : '');
        $sql->setValue('expires_at', $expiresAt !== null && $expiresAt !== '' ? $expiresAt : null);
        $sql->setValue('max_downloads', $maxDownloads ?? null);
        $sql->setValue('download_count', 0);
        $sql->setValue('status', 1);
        $sql->setValue('created_by', $createdBy);
        $sql->setValue('last_download', null);
        $sql->setValue('createdate', rex_sql::datetime(time()));
        $sql->setValue('updatedate', rex_sql::datetime(time()));
        $sql->insert();

        return $token;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getShares(): array
    {
        return rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('klxm_restricted_file_share') . ' ORDER BY id DESC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getShareById(int $shareId): ?array
    {
        if ($shareId <= 0) {
            return null;
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('klxm_restricted_file_share') . ' WHERE id = ? LIMIT 1',
            [$shareId]
        );

        if ($rows === []) {
            return null;
        }

        return $rows[0];
    }

    /**
     * @param string[] $manualFiles
     * @param array<string, string> $manualGroups
     */
    public static function updateShare(
        int $shareId,
        string $shareMode,
        int $articleId,
        string $sourceMode,
        int $mediaCategoryId,
        array $manualFiles,
        array $manualGroups,
        array $categorizedGroups,
        bool $requestEnabled,
        int $requestValidDays,
        array $requestFields,
        ?string $requestIntroText,
        ?string $title,
        ?string $description,
        bool $allowZip,
        ?string $password,
        bool $clearPassword,
        ?string $expiresAt,
        ?int $maxDownloads
    ): void {
        $existing = self::getShareById($shareId);
        if ($existing === null) {
            return;
        }

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_file_share'));
        $sql->setWhere('id = :id', ['id' => $shareId]);
        $sql->setValue('share_mode', $shareMode);
        $sql->setValue('article_id', $articleId);
        $sql->setValue('source_mode', $sourceMode);
        $sql->setValue('media_category_id', $mediaCategoryId > 0 ? $mediaCategoryId : null);
        $sql->setValue('grouped_files', self::buildGroupedFilesPayload($sourceMode, $manualFiles, $manualGroups, $categorizedGroups));
        $sql->setValue('request_enabled', $requestEnabled ? 1 : 0);
        $sql->setValue('request_valid_days', max(1, $requestValidDays));
        $sql->setValue('request_form_json', self::encodeRequestFields($requestFields));
        $sql->setValue('request_intro_text', $requestIntroText ?? '');
        $sql->setValue('title', $title ?? '');
        $sql->setValue('description', $description ?? '');
        $sql->setValue('allow_zip', $allowZip ? 1 : 0);
        $sql->setValue('expires_at', $expiresAt !== null && $expiresAt !== '' ? $expiresAt : null);
        $sql->setValue('max_downloads', $maxDownloads ?? null);

        if ($clearPassword) {
            $sql->setValue('password_hash', '');
        } elseif ($password !== null && $password !== '') {
            $sql->setValue('password_hash', password_hash($password, PASSWORD_DEFAULT));
        } else {
            $sql->setValue('password_hash', (string) ($existing['password_hash'] ?? ''));
        }

        $sql->setValue('updatedate', rex_sql::datetime(time()));
        $sql->update();
    }

    public static function deleteShare(int $shareId): void
    {
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_file_share'));
        $sql->setWhere('id = :id', ['id' => $shareId]);
        $sql->delete();
        rex_unset_session('klxm_restricted_file_share_auth_' . $shareId);
    }

    /**
    * @return array<int, array{filename:string,title:string,description:string,updatedate:string,filesize:int,category_id:int,category_name:string}>
     */
    public static function getMediaForCategory(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return [];
        }

        $descriptionSelect = self::getMediaDescriptionSelect();
        $rows = rex_sql::factory()->getArray(
            'SELECT filename, title, ' . $descriptionSelect . ', updatedate, filesize, category_id FROM ' . rex::getTable('media') . ' WHERE category_id = ? ORDER BY filename ASC',
            [$categoryId]
        );

        $result = [];
        foreach ($rows as $row) {
            $fileSize = (int) ($row['filesize'] ?? 0);
            if ($fileSize <= 0) {
                $path = rex_path::media((string) $row['filename']);
                $fileSize = is_file($path) ? (int) filesize($path) : 0;
            }

            $result[] = [
                'filename' => (string) $row['filename'],
                'title' => (string) ($row['title'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'updatedate' => (string) ($row['updatedate'] ?? ''),
                'filesize' => $fileSize,
                'category_id' => (int) ($row['category_id'] ?? 0),
                'category_name' => self::getMediaCategoryName((int) ($row['category_id'] ?? 0)),
            ];
        }

        return $result;
    }

    public static function handleFrontendDownloadRequest(): bool
    {
        $token = trim(rex_request::get('klxm_board_share', 'string', ''));
        $downloadMode = trim(rex_request::get('klxm_board_share_download', 'string', ''));
        if ($downloadMode === '') {
            $downloadMode = trim(rex_request::post('klxm_board_share_download', 'string', ''));
        }

        if ($token === '' || $downloadMode === '') {
            return false;
        }

        rex_login::startSession();

        $articleId = rex_article::getCurrentId();
        $access = self::resolveGuestAccessContext($token, $articleId > 0 ? $articleId : null);
        if ($access['share'] === null && $articleId > 0) {
            // Early frontend hooks can run before the final article context is stable.
            // Retry without article binding to avoid false negatives for valid share tokens.
            $access = self::resolveGuestAccessContext($token, null);
        }
        $share = $access['share'];
        $passwordUnlocked = $access['password_unlocked'];
        $requestId = $access['request_id'];

        if ($share === null || (int) ($share['status'] ?? 0) !== 1) {
            self::sendText('Freigabe nicht gefunden.', rex_response::HTTP_NOT_FOUND);
        }

        if (!self::isAccessAllowed($share, $passwordUnlocked)) {
            self::sendText('Kein Zugriff auf diese Freigabe.', rex_response::HTTP_FORBIDDEN);
        }

        if ($downloadMode === 'file') {
            $filename = trim(rex_request::post('file', 'string', rex_request::get('file', 'string', '')));
            if ($filename === '') {
                self::sendText('Dateiname fehlt.', rex_response::HTTP_BAD_REQUEST);
            }
            self::downloadSingleFile($share, $filename, $requestId);
        }

        if ($downloadMode === 'preview') {
            $filename = trim(rex_request::get('file', 'string', ''));
            self::sendInlinePreview($share, $filename);
        }

        if ($downloadMode === 'preview_thumb') {
            $filename = trim(rex_request::get('file', 'string', ''));
            $variant = trim(rex_request::get('variant', 'string', 'small'));
            self::sendPdfThumbnailPreview($share, $filename, $variant);
        }

        if ($downloadMode === 'zip_async_create') {
            $zipKind = trim(rex_request::post('zip_kind', 'string', 'all'));
            $selected = [];
            if ($zipKind === 'selected') {
                $selectedRaw = rex_request::post('selected_files', 'array', []);
                foreach ($selectedRaw as $item) {
                    if (is_string($item) && $item !== '') {
                        $selected[] = $item;
                    }
                }
                $allowed = self::collectAllowedFilenames($share);
                $selected = array_values(array_filter(array_values(array_unique($selected)), static fn (string $filename): bool => in_array($filename, $allowed, true)));
            } else {
                $selected = self::collectAllowedFilenames($share);
            }

            if ($selected === []) {
                self::sendJson([
                    'ok' => false,
                    'message' => 'Keine gültigen Dateien ausgewählt.',
                ], rex_response::HTTP_BAD_REQUEST);
            }

            $downloadModeValue = $zipKind === 'selected' ? 'zip_selected' : 'zip_all';
            $jobToken = self::createAsyncZipJob($share, $selected, $downloadModeValue, $requestId);
            self::sendJson([
                'ok' => true,
                'job' => $jobToken,
                'status_url' => self::buildCurrentShareUrl($token, [
                    'klxm_board_share_download' => 'zip_async_status',
                    'zip_job' => $jobToken,
                ]),
            ]);
        }

        if ($downloadMode === 'zip_async_status') {
            $jobToken = trim(rex_request::get('zip_job', 'string', ''));
            if ($jobToken === '') {
                self::sendJson(['ok' => false, 'message' => 'Job fehlt.'], rex_response::HTTP_BAD_REQUEST);
            }

            $status = self::getAsyncZipJobStatus($jobToken, $share, $token);
            self::sendJson($status);
        }

        if ($downloadMode === 'zip_async_fetch') {
            $jobToken = trim(rex_request::get('zip_job', 'string', ''));
            if ($jobToken === '') {
                self::sendText('Job fehlt.', rex_response::HTTP_BAD_REQUEST);
            }
            self::sendAsyncZipFile($jobToken, $share);
        }

        if ($downloadMode === 'zip_all') {
            self::downloadZip($share, self::collectAllowedFilenames($share), 'zip_all', $requestId);
        }

        if ($downloadMode === 'zip_selected') {
            $selectedRaw = rex_request::post('selected_files', 'array', []);
            $selected = [];
            foreach ($selectedRaw as $item) {
                if (is_string($item) && $item !== '') {
                    $selected[] = $item;
                }
            }

            $allowed = self::collectAllowedFilenames($share);
            $selected = array_values(array_unique($selected));
            $selected = array_values(array_filter($selected, static fn (string $filename): bool => in_array($filename, $allowed, true)));

            if ($selected === []) {
                self::sendText('Keine gültigen Dateien ausgewählt.', rex_response::HTTP_BAD_REQUEST);
            }

            self::downloadZip($share, $selected, 'zip_selected', $requestId);
        }

        self::sendText('Unbekannter Download-Modus.', rex_response::HTTP_BAD_REQUEST);
    }

    public static function renderForCurrentArticle(?int $forcedShareId = null): string
    {
        $articleId = rex_article::getCurrentId();
        if ($articleId <= 0) {
            return '';
        }

        $automaticShareWarning = '';
        if ($forcedShareId === null) {
            $activeShares = self::getActiveArticleShares($articleId);
            if (count($activeShares) > 1 && self::isRedaxoUserLoggedIn()) {
                $automaticShareWarning = '<div class="uk-alert-warning" uk-alert>Hinweis: Für diesen Artikel sind mehrere aktive Freigaben vorhanden. Im Modus „Automatisch“ wird die neueste Freigabe verwendet. Für eindeutiges Verhalten bitte im Modul eine feste Freigabe auswählen.</div>';
            }
        }

        $share = null;
        if ($forcedShareId !== null && $forcedShareId > 0) {
            $share = self::getShareById($forcedShareId);
        }
        if ($share === null) {
            $share = self::findLatestShareForArticle($articleId);
        }
        if ($share === null || (int) ($share['status'] ?? 0) !== 1) {
            if (self::isRedaxoUserLoggedIn()) {
                return '<div class="uk-alert-warning" uk-alert>Keine aktive Dateifreigabe für diese Seite gefunden.</div>';
            }
            return '';
        }

        if (self::isRedaxoUserLoggedIn()) {
            $filesByGroup = self::getDisplayGroups($share);
            if ($filesByGroup === []) {
                return '<div class="uk-alert-primary" uk-alert>Aktuell sind keine Dateien verfügbar.</div>';
            }

            $token = trim((string) ($share['token_plain'] ?? ''));
            return $automaticShareWarning . self::renderShareList($share, $token, $filesByGroup);
        }

        $token = trim(rex_request::get('klxm_board_share', 'string', ''));
        $accessArticleId = ($forcedShareId !== null && $forcedShareId > 0) ? null : $articleId;
        $access = $token !== '' ? self::resolveGuestAccessContext($token, $accessArticleId) : ['share' => null, 'password_unlocked' => false, 'request_id' => null];
        $shareFromToken = $access['share'];
        $passwordUnlocked = $access['password_unlocked'];

        if ($forcedShareId !== null && $forcedShareId > 0 && is_array($shareFromToken) && (int) ($shareFromToken['id'] ?? 0) !== $forcedShareId) {
            $shareFromToken = null;
            $passwordUnlocked = false;
        }

        if ($shareFromToken === null) {
            return self::renderRequestArea($share, $token);
        }

        rex_login::startSession();

        $sessionKey = 'klxm_restricted_file_share_auth_' . (int) $shareFromToken['id'];
        $requiresPassword = (string) ($shareFromToken['password_hash'] ?? '') !== '';
        $passwordUnlocked = $passwordUnlocked || rex_session($sessionKey, 'int', 0) === 1;

        $passwordError = '';
        if ($requiresPassword && !$passwordUnlocked) {
            $submittedPassword = rex_request::post('klxm_board_share_password', 'string', '');
            if ($submittedPassword !== '') {
                if (password_verify($submittedPassword, (string) $shareFromToken['password_hash'])) {
                    rex_set_session($sessionKey, 1);
                    $passwordUnlocked = true;
                } else {
                    $passwordError = 'Das Passwort ist nicht korrekt.';
                }
            }
        }

        if (!self::isAccessAllowed($shareFromToken, $passwordUnlocked)) {
            return self::renderLockedMessage($token);
        }

        if ($requiresPassword && !$passwordUnlocked) {
            return self::renderPasswordForm($token, $passwordError);
        }

        $filesByGroup = self::getDisplayGroups($shareFromToken);
        if ($filesByGroup === []) {
            return '<div class="uk-alert-primary" uk-alert>Aktuell sind keine Dateien verfügbar.</div>';
        }

        return self::renderShareList($shareFromToken, $token, $filesByGroup);
    }

    /**
     * @param string[] $manualFiles
     * @param array<string, string> $manualGroups
     */
    private static function buildGroupedFilesJson(array $manualFiles, array $manualGroups): string
    {
        $groups = [];

        foreach ($manualFiles as $filename) {
            if (!is_string($filename) || $filename === '') {
                continue;
            }

            $group = trim((string) ($manualGroups[$filename] ?? ''));
            if ($group === '') {
                $group = 'Allgemein';
            }

            if (!isset($groups[$group])) {
                $groups[$group] = [];
            }
            $groups[$group][] = $filename;
        }

        $payload = [];
        foreach ($groups as $group => $files) {
            $payload[] = [
                'name' => $group,
                'files' => array_values(array_unique($files)),
            ];
        }

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param string[] $manualFiles
     * @param array<string, string> $manualGroups
     * @param array<int, array{name:string,source:string,media_category_id:int,files:array<int, string>}> $categorizedGroups
     */
    private static function buildGroupedFilesPayload(string $sourceMode, array $manualFiles, array $manualGroups, array $categorizedGroups): string
    {
        if ($sourceMode === 'categorized') {
            return (string) json_encode($categorizedGroups, JSON_UNESCAPED_UNICODE);
        }

        return self::buildGroupedFilesJson($manualFiles, $manualGroups);
    }

    /**
     * @param array<string, mixed> $share
     * @return array<int, array{name:string,files:array<int, array{filename:string,title:string,description:string,updatedate:string,filesize:int}>}>
     */
    private static function getDisplayGroups(array $share): array
    {
        $sourceMode = (string) ($share['source_mode'] ?? 'category');
        if ($sourceMode === 'categorized') {
            return self::buildCategorizedGroups($share);
        }

        if ($sourceMode === 'manual') {
            return self::buildManualGroups($share);
        }

        $mediaCategoryId = (int) ($share['media_category_id'] ?? 0);
        $files = self::getMediaForCategory($mediaCategoryId);
        if ($files === []) {
            return [];
        }

        return [[
            'name' => 'Alle Dateien',
            'files' => $files,
        ]];
    }

    /**
     * @param array<string, mixed> $share
    * @return array<int, array{name:string,files:array<int, array{filename:string,title:string,description:string,updatedate:string,filesize:int,category_id:int,category_name:string}>}>
     */
    private static function buildManualGroups(array $share): array
    {
        $raw = (string) ($share['grouped_files'] ?? '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $allFilenames = [];
        foreach ($decoded as $group) {
            if (!is_array($group) || !isset($group['files']) || !is_array($group['files'])) {
                continue;
            }
            foreach ($group['files'] as $filename) {
                if (is_string($filename) && $filename !== '') {
                    $allFilenames[] = $filename;
                }
            }
        }

        $allFilenames = array_values(array_unique($allFilenames));
        if ($allFilenames === []) {
            return [];
        }

        $rowsByFilename = self::loadMediaRowsByFilename($allFilenames);

        $result = [];
        foreach ($decoded as $group) {
            if (!is_array($group)) {
                continue;
            }

            $groupName = trim((string) ($group['name'] ?? 'Allgemein'));
            if ($groupName === '') {
                $groupName = 'Allgemein';
            }

            $files = [];
            if (isset($group['files']) && is_array($group['files'])) {
                foreach ($group['files'] as $filename) {
                    if (!is_string($filename)) {
                        continue;
                    }
                    if (isset($rowsByFilename[$filename])) {
                        $files[] = $rowsByFilename[$filename];
                    }
                }
            }

            if ($files !== []) {
                $result[] = [
                    'name' => $groupName,
                    'files' => $files,
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $share
    * @return array<int, array{name:string,files:array<int, array{filename:string,title:string,description:string,updatedate:string,filesize:int,category_id:int,category_name:string}>}>
     */
    private static function buildCategorizedGroups(array $share): array
    {
        $raw = (string) ($share['grouped_files'] ?? '[]');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $group) {
            if (!is_array($group)) {
                continue;
            }

            $name = trim((string) ($group['name'] ?? 'Kategorie'));
            if ($name === '') {
                $name = 'Kategorie';
            }

            $source = trim((string) ($group['source'] ?? 'manual'));
            $files = [];

            if ($source === 'media_category') {
                $categoryId = (int) ($group['media_category_id'] ?? 0);
                if ($categoryId > 0) {
                    $files = self::getMediaForCategory($categoryId);
                }
            } else {
                $groupFiles = [];
                if (isset($group['files']) && is_array($group['files'])) {
                    foreach ($group['files'] as $file) {
                        if (is_string($file) && $file !== '') {
                            $groupFiles[] = $file;
                        }
                    }
                }

                // Backward compatibility: legacy format had only name + files.
                if ($groupFiles === [] && isset($group['media_files']) && is_array($group['media_files'])) {
                    foreach ($group['media_files'] as $file) {
                        if (is_string($file) && $file !== '') {
                            $groupFiles[] = $file;
                        }
                    }
                }

                $groupFiles = array_values(array_unique($groupFiles));
                if ($groupFiles !== []) {
                    $rowsByFilename = self::loadMediaRowsByFilename($groupFiles);
                    foreach ($groupFiles as $filename) {
                        if (isset($rowsByFilename[$filename])) {
                            $files[] = $rowsByFilename[$filename];
                        }
                    }
                }
            }

            if ($files !== []) {
                $result[] = [
                    'name' => $name,
                    'files' => $files,
                ];
            }
        }

        return $result;
    }

    /**
     * @param string[] $filenames
    * @return array<string, array{filename:string,title:string,description:string,updatedate:string,filesize:int,category_id:int,category_name:string}>
     */
    private static function loadMediaRowsByFilename(array $filenames): array
    {
        if ($filenames === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($filenames), '?'));
        $descriptionSelect = self::getMediaDescriptionSelect();
        $rows = rex_sql::factory()->getArray(
            'SELECT filename, title, ' . $descriptionSelect . ', updatedate, filesize, category_id FROM ' . rex::getTable('media') . ' WHERE filename IN (' . $placeholders . ')',
            $filenames
        );

        $indexed = [];
        foreach ($rows as $row) {
            $filename = (string) $row['filename'];
            $fileSize = (int) ($row['filesize'] ?? 0);
            if ($fileSize <= 0) {
                $path = rex_path::media($filename);
                $fileSize = is_file($path) ? (int) filesize($path) : 0;
            }

            $indexed[$filename] = [
                'filename' => $filename,
                'title' => (string) ($row['title'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'updatedate' => (string) ($row['updatedate'] ?? ''),
                'filesize' => $fileSize,
                'category_id' => (int) ($row['category_id'] ?? 0),
                'category_name' => self::getMediaCategoryName((int) ($row['category_id'] ?? 0)),
            ];
        }

        return $indexed;
    }

    private static function getMediaDescriptionSelect(): string
    {
        if (self::$mediaDescriptionSelect !== null) {
            return self::$mediaDescriptionSelect;
        }

        $table = rex::getTable('media');
        $columns = rex_sql::factory()->getArray('SHOW COLUMNS FROM ' . $table);

        $hasDescription = false;
        $hasMedDescription = false;
        foreach ($columns as $column) {
            $field = (string) ($column['Field'] ?? '');
            if ($field === 'description') {
                $hasDescription = true;
                break;
            }
            if ($field === 'med_description') {
                $hasMedDescription = true;
            }
        }

        if ($hasDescription) {
            self::$mediaDescriptionSelect = 'description';
            return self::$mediaDescriptionSelect;
        }

        if ($hasMedDescription) {
            self::$mediaDescriptionSelect = 'med_description AS description';
            return self::$mediaDescriptionSelect;
        }

        self::$mediaDescriptionSelect = "'' AS description";
        return self::$mediaDescriptionSelect;
    }

    /**
     * @return array{title:string, subtitle:string, accent:string, logo:string}
     */
    private static function getShareBranding(): array
    {
        $addon = rex_addon::get('klxm_restricted');
        $title = trim((string) $addon->getConfig('share_brand_title', ''));
        $subtitle = trim((string) $addon->getConfig('share_brand_subtitle', ''));
        $accent = trim((string) $addon->getConfig('share_brand_accent', '#0f6eb8'));
        $logoRaw = trim((string) $addon->getConfig('share_brand_logo', ''));

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $accent = '#0f6eb8';
        }

        $logo = '';
        if ($logoRaw !== '') {
            if (preg_match('/^https?:\/\//i', $logoRaw) === 1) {
                $logo = $logoRaw;
            } else {
                $logo = rex_url::media($logoRaw);
            }
        }

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'accent' => $accent,
            'logo' => $logo,
        ];
    }

    /**
     * @param array{title:string, subtitle:string, accent:string, logo:string} $branding
     */
    private static function renderShareBrandingHeader(array $branding): string
    {
        if ($branding['title'] === '' && $branding['subtitle'] === '' && $branding['logo'] === '') {
            return '';
        }

        $html = '<div class="klxm-brand">';
        if ($branding['logo'] !== '') {
            $html .= '<img class="klxm-brand-logo" src="' . htmlspecialchars($branding['logo']) . '" alt="Logo">';
        }
        $html .= '<div class="klxm-brand-text">';
        if ($branding['title'] !== '') {
            $html .= '<h2>' . htmlspecialchars($branding['title']) . '</h2>';
        }
        if ($branding['subtitle'] !== '') {
            $html .= '<p>' . htmlspecialchars($branding['subtitle']) . '</p>';
        }
        $html .= '</div></div>';

        return $html;
    }

    private static function renderShareBaseStyles(string $accent): string
    {
        return '<style>'
            . ':root{--klxm-accent:' . htmlspecialchars($accent) . ';--klxm-text:#12202f;--klxm-muted:#5f7286;--klxm-line:#d8e0ea;--klxm-card:#ffffff;--klxm-bg:#f3f6fb}'
            . '*,*::before,*::after{box-sizing:border-box}'
            . '.uk-section{padding:18px 0;font-family:Arial,Helvetica,sans-serif;color:var(--klxm-text)}.uk-container{max-width:1100px;margin:0 auto;padding:0 12px}.uk-card{background:var(--klxm-card);border:1px solid var(--klxm-line);border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.06)}'
            . '.uk-card-body{padding:16px}.uk-card-title{margin:0 0 8px;font-size:1.35rem;line-height:1.3;color:var(--klxm-text)}.uk-text-meta{color:var(--klxm-muted);font-size:.92rem}'
            . '.uk-margin-top{margin-top:14px}.uk-margin-medium{margin-top:20px}.uk-margin-medium-bottom{margin-bottom:20px}.uk-margin-small-top{margin-top:8px}'
            . '.uk-flex{display:flex}.uk-flex-wrap{flex-wrap:wrap}.uk-flex-middle{align-items:center}.uk-grid-small{gap:8px}.uk-width-auto\@s{flex:0 0 auto}.uk-width-expand\@s{flex:1 1 280px}'
            . '.uk-button,.uk-icon-button{display:inline-flex;align-items:center;justify-content:center;padding:9px 12px;border-radius:8px;border:1px solid var(--klxm-line);background:#fff;color:var(--klxm-text);cursor:pointer;text-decoration:none;font-weight:600}'
            . '.uk-button-secondary,.uk-button-primary{background:var(--klxm-accent);border-color:var(--klxm-accent);color:#fff}.uk-button[disabled]{opacity:.55;cursor:not-allowed}'
            . '.uk-button-group{display:inline-flex;gap:6px}.uk-inline{position:relative}.uk-width-1-1{width:100%}.uk-inline.uk-width-1-1{display:block}.uk-form-icon{display:none}.uk-input,.uk-select,.uk-textarea{width:100%;padding:10px 11px;border:1px solid var(--klxm-line);border-radius:8px;background:#fff;color:var(--klxm-text)}'
            . '.uk-overflow-auto{overflow:auto}.uk-table{width:100%;border-collapse:collapse;background:#fff}.uk-table th,.uk-table td{border-bottom:1px solid var(--klxm-line);padding:8px 6px;text-align:left;font-size:.92rem;vertical-align:top}'
            . '.uk-table thead th{font-size:.82rem;text-transform:uppercase;letter-spacing:.03em;color:var(--klxm-muted)}.uk-table-hover tbody tr:hover{background:#f8fbff}.uk-text-right{text-align:right}.uk-text-nowrap{white-space:nowrap}.uk-hidden-visually{position:absolute;left:-9999px}'
            . '.uk-heading-bullet{margin:0 0 10px;font-size:1.08rem;color:var(--klxm-text)}.uk-heading-bullet span{border-left:4px solid var(--klxm-accent);padding-left:8px}'
                . '.klxm-share-toolbar{position:sticky;background:var(--klxm-bg);overflow:hidden}.klxm-group-block{margin-bottom:20px}'
                . '.klxm-toolbar-main{display:flex;flex-wrap:wrap;align-items:center;gap:10px}'
                . '.klxm-toolbar-zip{flex:0 0 auto}.klxm-toolbar-status{flex:1 1 240px;min-width:0}.klxm-search-wrap{flex:1 1 360px;min-width:0}'
                . '.klxm-search-wrap .uk-inline{display:block;width:100%}.klxm-live-search{display:block;width:100%;max-width:100%;min-width:0}'
                . '.klxm-sort-wrap,.klxm-jump-wrap{flex:0 1 280px;min-width:220px;max-width:100%}.klxm-sort-wrap .uk-select,.klxm-jump-wrap .uk-select{width:100%;max-width:100%}'
            . '.uk-icon-button{min-width:38px;min-height:38px;padding:0}.klxm-download-icon{width:18px;height:18px;display:block;fill:currentColor}'
            . '.klxm-brand{display:flex;align-items:center;gap:12px;margin-bottom:12px}.klxm-brand-logo{max-height:56px;max-width:220px;object-fit:contain}.klxm-brand h2{margin:0;color:var(--klxm-accent);font-size:1rem;letter-spacing:.04em;text-transform:uppercase}.klxm-brand p{margin:4px 0 0;color:var(--klxm-muted)}'
            . '.uk-alert-warning,.uk-alert-danger,.uk-alert-primary,.uk-alert-success{padding:10px 12px;border-radius:8px;border:1px solid var(--klxm-line);margin:8px 0}'
            . '.uk-alert-warning{background:#fff8e6;color:#7a5f00}.uk-alert-danger{background:#fff0f0;color:#8f2f2f}.uk-alert-primary{background:#eef5ff;color:#2a4d7a}.uk-alert-success{background:#ecf9f0;color:#2d6b46}'
            . '.klxm-files-table{table-layout:fixed;width:100%}.klxm-files-table th,.klxm-files-table td{vertical-align:top;word-wrap:break-word}'
            . '.klxm-desc-excerpt{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word}'
            . '.klxm-desc-full{margin-top:6px;word-break:break-word}.klxm-desc-full[hidden]{display:none!important}'
            . '.klxm-desc-toggle{margin-top:6px;padding:0;min-height:auto;font-size:.82rem;line-height:1.2}'
            . '.klxm-zip-status-modal{display:none;position:fixed;z-index:10050;inset:0;background:rgba(0,0,0,.42)}.klxm-zip-status-modal .uk-modal-dialog{max-width:520px;margin:10vh auto;background:#fff;border-radius:10px;padding:16px;border:1px solid var(--klxm-line)}'
                . '.klxm-preview-link{display:inline-flex;width:84px;height:56px;align-items:center;justify-content:center;border:1px solid #e5e5e5;border-radius:4px;background:#fff;overflow:hidden}'
                . '.klxm-preview-thumb{display:block;max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain}'
                . '.klxm-filetype-tile{width:84px;height:56px;border:1px solid #e5e5e5;border-radius:4px;background:#f8f8f8;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px}'
                . '.klxm-filetype-icon{width:18px;height:18px;display:block;fill:#5f7286}.klxm-filetype-label{font-size:10px;line-height:1;color:#5f7286;font-weight:700;letter-spacing:.03em}'
                . '.klxm-preview-overlay{display:none;position:fixed;inset:0;z-index:11000;background:rgba(7,16,28,.86);padding:20px}'
                . '.klxm-preview-overlay.is-open{display:flex;align-items:center;justify-content:center}'
                . '.klxm-preview-dialog{position:relative;width:min(1120px,calc(100vw - 40px));max-height:calc(100vh - 40px);background:#fff;border-radius:10px;box-shadow:0 24px 56px rgba(0,0,0,.35);overflow:hidden}'
                . '.klxm-preview-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;border-bottom:1px solid var(--klxm-line)}'
                . '.klxm-preview-title{font-size:.95rem;font-weight:700;color:var(--klxm-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
                . '.klxm-preview-close{border:1px solid var(--klxm-line);background:#fff;border-radius:8px;padding:6px 10px;cursor:pointer;font-weight:700}'
                . '.klxm-preview-body{background:#0f1724;min-height:280px;max-height:calc(100vh - 110px);display:flex;align-items:center;justify-content:center}'
                . '.klxm-preview-body img{max-width:100%;max-height:calc(100vh - 140px);display:block}.klxm-preview-body iframe{width:min(1040px,96vw);height:min(78vh,980px);border:0;background:#fff}.klxm-preview-body video{width:min(1040px,96vw);max-height:calc(100vh - 140px);background:#000;display:block}'
                . '@media (max-width:980px){.klxm-sort-wrap,.klxm-jump-wrap{min-width:180px}}'
                . '@media (max-width:860px){.uk-card-body{padding:12px}.uk-button,.uk-input,.uk-select,.uk-textarea{font-size:14px}.uk-table th,.uk-table td{font-size:.86rem}.klxm-brand{align-items:flex-start;flex-direction:column}.klxm-toolbar-main{align-items:stretch}.klxm-toolbar-zip,.klxm-toolbar-status,.klxm-search-wrap,.klxm-sort-wrap,.klxm-jump-wrap{flex:1 1 100%;min-width:0;width:100%}.uk-button-group{display:flex;flex-wrap:wrap}.uk-button-group .uk-button{flex:1 1 auto}.klxm-preview-overlay{padding:10px}.klxm-preview-dialog{width:calc(100vw - 20px);max-height:calc(100vh - 20px)}.klxm-preview-body iframe{width:100%;height:74vh}}'
            . '</style>';
    }

            private static function renderDownloadIconSvg(): string
            {
            return '<svg class="klxm-download-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3a1 1 0 0 1 1 1v8.59l2.3-2.29a1 1 0 1 1 1.4 1.42l-4 3.97a1 1 0 0 1-1.4 0l-4-3.97a1 1 0 0 1 1.4-1.42L11 12.59V4a1 1 0 0 1 1-1Zm-7 14a1 1 0 0 1 1 1v1h12v-1a1 1 0 1 1 2 0v2a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-2a1 1 0 0 1 1-1Z"/></svg>';
            }

    private static function getMediaCategoryName(int $categoryId): string
    {
        if ($categoryId <= 0) {
            return '';
        }

        if (isset(self::$mediaCategoryNameCache[$categoryId])) {
            return self::$mediaCategoryNameCache[$categoryId];
        }

        $category = rex_media_category::get($categoryId);
        $name = $category ? trim($category->getName()) : '';
        self::$mediaCategoryNameCache[$categoryId] = $name;

        return $name;
    }

    /**
     * @param array<int, array{name:string,files:array<int, array{filename:string,title:string,description:string,updatedate:string,filesize:int,category_id:int,category_name:string}>}> $groups
     * @return array<int, array{name:string,files:array<int, array{filename:string,title:string,description:string,updatedate:string,filesize:int,category_id:int,category_name:string}>}>
     */
    private static function sortFilesByCategoryAndTitle(array $groups, string $direction): array
    {
        $dir = strtolower(trim($direction));
        if ($dir === 'manual') {
            return $groups;
        }
        if ($dir !== 'asc' && $dir !== 'desc') {
            $dir = 'asc';
        }

        foreach ($groups as $groupIndex => $group) {
            if (!isset($group['files']) || !is_array($group['files'])) {
                continue;
            }

            usort($group['files'], static function (array $a, array $b) use ($dir): int {
                $aCategory = strtolower(trim((string) ($a['category_name'] ?? '')));
                $bCategory = strtolower(trim((string) ($b['category_name'] ?? '')));

                $aTitle = trim((string) ($a['title'] ?? ''));
                if ($aTitle === '') {
                    $aTitle = (string) ($a['filename'] ?? '');
                }
                $bTitle = trim((string) ($b['title'] ?? ''));
                if ($bTitle === '') {
                    $bTitle = (string) ($b['filename'] ?? '');
                }

                $aTitle = strtolower($aTitle);
                $bTitle = strtolower($bTitle);
                $aFilename = strtolower((string) ($a['filename'] ?? ''));
                $bFilename = strtolower((string) ($b['filename'] ?? ''));

                $result = $aCategory <=> $bCategory;
                if ($result === 0) {
                    $result = $aTitle <=> $bTitle;
                }
                if ($result === 0) {
                    $result = $aFilename <=> $bFilename;
                }

                return $dir === 'desc' ? (-1 * $result) : $result;
            });

            $groups[$groupIndex]['files'] = $group['files'];
        }

        return $groups;
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function renderShareList(array $share, string $token, array $filesByGroup): string
    {
        $branding = self::getShareBranding();
        $sortDirection = strtolower(trim(rex_request::get('klxm_sort', 'string', 'manual')));
        if (!in_array($sortDirection, ['manual', 'asc', 'desc'], true)) {
            $sortDirection = 'manual';
        }
        $filesByGroup = self::sortFilesByCategoryAndTitle($filesByGroup, $sortDirection);

        $headline = trim((string) ($share['title'] ?? ''));
        if ($headline === '') {
            $headline = 'Dateifreigabe';
        }

        $description = trim((string) ($share['description'] ?? ''));
        $allowZip = (int) ($share['allow_zip'] ?? 0) === 1;
        $stickyOffset = (int) rex_addon::get('klxm_restricted')->getConfig('share_sticky_offset', 96);
        if ($stickyOffset < 0) {
            $stickyOffset = 0;
        }
        if ($stickyOffset > 640) {
            $stickyOffset = 640;
        }

        $zipModalId = 'klxm-zip-status-modal-' . (int) $share['id'];
        $statusCreateUrl = self::buildCurrentShareUrl($token, ['klxm_board_share_download' => 'zip_async_create']);
        $hasJumpMenu = count($filesByGroup) > 2;

        $html = self::renderShareBaseStyles($branding['accent']);
        $html .= '<section class="uk-section uk-section-small" data-klxm-share-id="' . (int) $share['id'] . '">';
        $html .= '<div class="uk-container">';
        $html .= '<div class="uk-card uk-card-default uk-card-body">';
        $html .= self::renderShareBrandingHeader($branding);
        $html .= '<h2 class="uk-card-title">' . htmlspecialchars($headline) . '</h2>';
        if ($description !== '') {
            $html .= '<p class="uk-text-meta">' . nl2br(htmlspecialchars($description)) . '</p>';
        }
        $html .= '</div>';

        $html .= '<div class="uk-card uk-card-default uk-card-body uk-margin-top uk-margin-medium-bottom klxm-share-toolbar" style="position:sticky;top:' . $stickyOffset . 'px;z-index:95;">';
        $html .= '<div class="uk-flex uk-flex-wrap uk-flex-middle uk-grid-small klxm-toolbar-main" uk-grid>';

        if ($allowZip) {
            $html .= '<div class="uk-width-auto@s klxm-toolbar-zip">';
            $html .= '<div class="uk-button-group">';
            $html .= '<button type="button" class="uk-button uk-button-default klxm-zip-all-btn">Alle als ZIP</button>';
            $html .= '<button type="button" class="uk-button uk-button-secondary klxm-zip-selected-btn" disabled>Ausgewählte als ZIP</button>';
            $html .= '</div></div>';
            $html .= '<div class="uk-width-expand@s klxm-toolbar-status"><span class="uk-text-meta klxm-zip-status"></span></div>';
        }

        $html .= '<div class="uk-width-expand@s klxm-search-wrap">';
        $html .= '<div class="uk-inline uk-width-1-1">';
        $html .= '<span class="uk-form-icon" uk-icon="icon: search"></span>';
        $html .= '<input class="uk-input klxm-live-search" type="text" placeholder="Dateien filtern (Name, Dateiname, Beschreibung)">';
        $html .= '</div></div>';

        $html .= '<div class="uk-width-auto@s klxm-sort-wrap">';
        $html .= '<select class="uk-select klxm-sort-select" title="Sortierung">';
        $html .= '<option value="manual"' . ($sortDirection === 'manual' ? ' selected' : '') . '>Sortierung: Manuelle Reihenfolge</option>';
        $html .= '<option value="asc"' . ($sortDirection === 'asc' ? ' selected' : '') . '>Sortierung: Kategorie + Titel A-Z</option>';
        $html .= '<option value="desc"' . ($sortDirection === 'desc' ? ' selected' : '') . '>Sortierung: Kategorie + Titel Z-A</option>';
        $html .= '</select></div>';

        if ($hasJumpMenu) {
            $html .= '<div class="uk-width-auto@s klxm-jump-wrap">';
            $html .= '<select class="uk-select klxm-jump-menu">';
            $html .= '<option value="">Sprungmenü</option>';
            foreach ($filesByGroup as $groupIndex => $group) {
                $anchorId = 'klxm-group-anchor-' . (int) $share['id'] . '-' . $groupIndex;
                $html .= '<option value="#' . htmlspecialchars($anchorId) . '">' . htmlspecialchars($group['name']) . '</option>';
            }
            $html .= '</select></div>';
        }

        $html .= '</div></div>';

        if ($allowZip) {
            $html .= '<div id="' . htmlspecialchars($zipModalId) . '" class="klxm-zip-status-modal" uk-modal>';
            $html .= '<div class="uk-modal-dialog uk-modal-body">';
            $html .= '<h3 class="uk-modal-title">ZIP-Download</h3>';
            $html .= '<div class="uk-flex uk-flex-middle uk-gap-small">';
            $html .= '<span class="klxm-zip-modal-spinner" uk-spinner="ratio: 0.8"></span>';
            $html .= '<span class="klxm-zip-modal-message">ZIP wird vorbereitet ...</span>';
            $html .= '</div>';
            $html .= '<p class="uk-text-danger uk-margin-small-top klxm-zip-modal-error" hidden></p>';
            $html .= '<div class="uk-margin-top uk-text-right">';
            $html .= '<button class="uk-button uk-button-default uk-modal-close" type="button">Schließen</button>';
            $html .= '</div></div></div>';
        }

        $previewModalId = 'klxm-preview-modal-' . (int) $share['id'];
        $html .= '<div id="' . htmlspecialchars($previewModalId) . '" class="klxm-preview-overlay" aria-hidden="true">';
        $html .= '<div class="klxm-preview-dialog" role="dialog" aria-modal="true" aria-label="Dateivorschau">';
        $html .= '<div class="klxm-preview-head">';
        $html .= '<div class="klxm-preview-title"></div>';
        $html .= '<button type="button" class="klxm-preview-close" aria-label="Vorschau schließen">Schließen</button>';
        $html .= '</div>';
        $html .= '<div class="klxm-preview-body"></div>';
        $html .= '</div></div>';

        foreach ($filesByGroup as $groupIndex => $group) {
            $groupId = 'klxm-file-group-' . (int) $share['id'] . '-' . $groupIndex;
            $groupAnchorId = 'klxm-group-anchor-' . (int) $share['id'] . '-' . $groupIndex;
            $downloadCsrf = rex_csrf_token::factory('klxm_restricted_file_share_download')->getValue();
            $html .= '<div class="uk-margin-medium klxm-group-block" id="' . htmlspecialchars($groupAnchorId) . '">';
            $html .= '<h3 class="uk-heading-bullet"><span>' . htmlspecialchars($group['name']) . '</span></h3>';
            $html .= '<form method="post" class="klxm-zip-selected-form" data-share-token="' . htmlspecialchars($token) . '">';
            $html .= '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($downloadCsrf) . '">';
            $html .= '<div class="uk-overflow-auto">';
            $html .= '<table class="uk-table uk-table-divider uk-table-small uk-table-hover klxm-files-table">';
            $html .= '<colgroup>';
            $html .= '<col style="width:40px">';
            $html .= '<col style="width:110px">';
            $html .= '<col style="width:28%">';
            $html .= '<col style="width:32%">';
            $html .= '<col style="width:130px">';
            $html .= '<col style="width:110px">';
            $html .= '<col style="width:78px">';
            $html .= '</colgroup>';
            $html .= '<thead><tr>';
            $html .= '<th style="width:40px;"><input class="klxm-select-group" type="checkbox" data-target="' . htmlspecialchars($groupId) . '"></th>';
            $html .= '<th style="width:110px;">Vorschau</th>';
            $html .= '<th>Datei</th><th>Beschreibung</th><th>Aktualisiert</th><th class="uk-text-right">Groesse</th><th></th>';
            $html .= '</tr></thead><tbody id="' . htmlspecialchars($groupId) . '">';

            foreach ($group['files'] as $fileIndex => $file) {
                $displayName = trim($file['title']) !== '' ? $file['title'] : $file['filename'];
                $singleActionUrl = self::buildShareUrl($share, $token, [
                    'klxm_board_share_download' => 'file',
                    'file' => $file['filename'],
                ]);
                $previewHtml = self::renderFilePreview($share, $token, $file, $displayName);
                $descriptionHtml = self::renderDescriptionCell((string) $file['description'], (int) $share['id'], (int) $groupIndex, (int) $fileIndex);
                $searchText = strtolower($displayName . ' ' . $file['filename'] . ' ' . $file['description']);
                $html .= '<tr data-search="' . htmlspecialchars($searchText) . '">';
                $html .= '<td><input class="klxm-file-checkbox" type="checkbox" name="selected_files[]" value="' . htmlspecialchars($file['filename']) . '"></td>';
                $html .= '<td>' . $previewHtml . '</td>';
                $html .= '<td><strong>' . htmlspecialchars($displayName) . '</strong><br><span class="uk-text-meta">' . htmlspecialchars($file['filename']) . '</span></td>';
                $html .= '<td>' . $descriptionHtml . '</td>';
                $html .= '<td>' . htmlspecialchars(self::formatDate($file['updatedate'])) . '</td>';
                $html .= '<td class="uk-text-right">' . htmlspecialchars(self::formatBytes($file['filesize'])) . '</td>';
                $html .= '<td class="uk-text-nowrap">';
                $html .= '<input type="hidden" name="klxm_board_share_download" value="file">';
                $html .= '<button type="submit" class="uk-icon-button" '
                    . 'formaction="' . htmlspecialchars($singleActionUrl) . '" '
                    . 'formmethod="post" '
                    . 'name="file" '
                    . 'value="' . htmlspecialchars($file['filename']) . '" '
                    . 'title="Datei herunterladen" '
                    . 'aria-label="Datei herunterladen: ' . htmlspecialchars($displayName) . '">'
                    . self::renderDownloadIconSvg()
                    . '<span class="uk-hidden-visually">Datei herunterladen</span>'
                    . '</button>';
                $html .= '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table></div>';
            $html .= '</form></div>';
        }

        $html .= '</div></section>';
        $html .= '<script>(function(){'
            . 'var root=document.querySelector("[data-klxm-share-id=\"' . (int) $share['id'] . '\"]");'
            . 'if(!root||root.getAttribute("data-klxm-share-init")==="1"){return;}'
            . 'root.setAttribute("data-klxm-share-init","1");'
            . 'var createUrl=' . json_encode($statusCreateUrl) . ';'
            . 'var modal=document.getElementById(' . json_encode($zipModalId) . ');'
            . 'var previewModal=document.getElementById(' . json_encode($previewModalId) . ');'
            . 'function decodeUrl(u){return String(u||"").replace(/&amp;/g,"&");}'
            . 'function showModal(){if(!modal){return;}if(window.UIkit&&typeof window.UIkit.modal==="function"){window.UIkit.modal(modal).show();return;}modal.style.display="block";}'
            . 'function hideModal(){if(!modal){return;}if(window.UIkit&&typeof window.UIkit.modal==="function"){window.UIkit.modal(modal).hide();return;}modal.style.display="none";}'
            . 'function closePreview(){if(!previewModal){return;}previewModal.classList.remove("is-open");previewModal.setAttribute("aria-hidden","true");var body=previewModal.querySelector(".klxm-preview-body");if(body){body.innerHTML="";}}'
            . 'function openPreview(url,title,type){if(!previewModal){return;}var head=previewModal.querySelector(".klxm-preview-title");var body=previewModal.querySelector(".klxm-preview-body");if(head){head.textContent=title||"Vorschau";}if(body){if(type==="pdf"){body.innerHTML="<iframe src=\""+url.replace(/\"/g,"&quot;")+"\" loading=\"lazy\" title=\"Dateivorschau\"></iframe>";}else if(type==="video"){body.innerHTML="<video controls preload=\"metadata\" playsinline src=\""+url.replace(/\"/g,"&quot;")+"\"></video>";}else{body.innerHTML="<img src=\""+url.replace(/\"/g,"&quot;")+"\" alt=\""+(title||"Vorschau").replace(/\"/g,"&quot;")+"\">";}}previewModal.classList.add("is-open");previewModal.setAttribute("aria-hidden","false");}'
            . 'function setModalState(msg,isError,isReady){if(!modal){return;}var msgNode=modal.querySelector(".klxm-zip-modal-message");var errNode=modal.querySelector(".klxm-zip-modal-error");var spinNode=modal.querySelector(".klxm-zip-modal-spinner");if(msgNode){msgNode.textContent=msg;}if(spinNode){spinNode.hidden=!!isError||!!isReady;}if(errNode){if(isError){errNode.hidden=false;errNode.textContent=msg;}else{errNode.hidden=true;errNode.textContent="";}}}'
            . 'function setStatus(msg){root.querySelectorAll(".klxm-zip-status").forEach(function(el){el.textContent=msg;});if(msg!==""){showModal();var low=msg.toLowerCase();var isReady=low.indexOf("bereit")!==-1;var isError=low.indexOf("fehler")!==-1||low.indexOf("konnte nicht")!==-1||low.indexOf("fehlgeschlagen")!==-1;setModalState(msg,isError,isReady);if(isReady){window.setTimeout(hideModal,2000);}}}'
            . 'function collectSelected(){var selected=[];root.querySelectorAll(".klxm-file-checkbox:checked").forEach(function(cb){selected.push(cb.value);});return selected;}'
            . 'function refreshSelectedButton(){var btn=root.querySelector(".klxm-zip-selected-btn");if(!btn){return;}btn.disabled=collectSelected().length===0;}'
            . 'function poll(job){var u=new URL(decodeUrl(createUrl),window.location.origin);u.searchParams.set("klxm_board_share_download","zip_async_status");u.searchParams.set("zip_job",job);fetch(u.toString(),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(data){if(!data||!data.ok){setStatus((data&&data.message)?data.message:"ZIP-Statusfehler");return;}if(data.status==="queued"||data.status==="processing"){setStatus("ZIP wird erstellt ...");window.setTimeout(function(){poll(job);},1200);return;}if(data.status==="ready"){setStatus("ZIP bereit, Download startet ...");window.location.href=decodeUrl(data.download_url||"");window.setTimeout(function(){setStatus("");},2800);return;}setStatus(data.message||"ZIP fehlgeschlagen");}).catch(function(){setStatus("ZIP-Statusfehler");});}'
            . 'function create(kind,selected){setModalState("ZIP wird vorbereitet ...",false,false);showModal();var formData=new FormData();formData.set("zip_kind",kind);selected.forEach(function(name){formData.append("selected_files[]",name);});fetch(decodeUrl(createUrl),{method:"POST",body:formData,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(data){if(!data||!data.ok){setStatus((data&&data.message)?data.message:"ZIP konnte nicht gestartet werden");return;}setStatus("ZIP wird erstellt ...");poll(data.job);}).catch(function(){setStatus("ZIP konnte nicht gestartet werden");});}'
            . 'function applySearch(needle){var query=(needle||"").toLowerCase().trim();root.querySelectorAll("tbody tr[data-search]").forEach(function(row){var hay=(row.getAttribute("data-search")||"").toLowerCase();row.style.display=(query===""||hay.indexOf(query)!==-1)?"":"none";});root.querySelectorAll(".klxm-group-block").forEach(function(block){var visibleRows=block.querySelectorAll("tbody tr[data-search]:not([style*=\"display: none\"])").length;block.style.display=visibleRows>0?"":"none";});}'
            . 'root.addEventListener("change",function(e){var t=e.target;if(t.classList.contains("klxm-select-group")){var id=t.getAttribute("data-target")||"";var wrap=document.getElementById(id);if(wrap){wrap.querySelectorAll(".klxm-file-checkbox").forEach(function(cb){cb.checked=t.checked;});}refreshSelectedButton();return;}if(t.classList.contains("klxm-file-checkbox")){refreshSelectedButton();return;}if(t.classList.contains("klxm-jump-menu")){var target=t.value||"";if(target!==""){var el=document.querySelector(target);if(el){el.scrollIntoView({behavior:"smooth",block:"start"});}}return;}if(t.classList.contains("klxm-sort-select")){var value=(t.value||"manual").toLowerCase();if(value!=="asc"&&value!=="desc"&&value!=="manual"){value="manual";}var u=new URL(window.location.href);if(value==="manual"){u.searchParams.delete("klxm_sort");}else{u.searchParams.set("klxm_sort",value);}window.location.href=u.toString();return;}});'
            . 'root.addEventListener("input",function(e){var t=e.target;if(t.classList.contains("klxm-live-search")){applySearch(t.value||"");}});'
            . 'root.addEventListener("click",function(e){var descToggle=e.target.closest(".klxm-desc-toggle");if(descToggle){e.preventDefault();var targetId=descToggle.getAttribute("data-target")||"";var full=document.getElementById(targetId);if(full){var show=full.hasAttribute("hidden");if(show){full.removeAttribute("hidden");descToggle.textContent="Weniger";descToggle.setAttribute("aria-expanded","true");}else{full.setAttribute("hidden","");descToggle.textContent="Mehr";descToggle.setAttribute("aria-expanded","false");}}return;}var preview=e.target.closest(".klxm-preview-trigger");if(preview){e.preventDefault();openPreview(decodeUrl(preview.getAttribute("data-preview-url")||""),preview.getAttribute("data-preview-title")||"",preview.getAttribute("data-preview-type")||"image");return;}var allBtn=e.target.closest(".klxm-zip-all-btn");if(allBtn){e.preventDefault();create("all",[]);return;}var selectedBtn=e.target.closest(".klxm-zip-selected-btn");if(selectedBtn){e.preventDefault();var selected=collectSelected();if(selected.length===0){setStatus("Bitte zuerst Dateien auswählen.");return;}create("selected",selected);}});'
            . 'if(previewModal){previewModal.addEventListener("click",function(e){if(e.target===previewModal||e.target.closest(".klxm-preview-close")){closePreview();}});document.addEventListener("keydown",function(e){if(e.key==="Escape"&&previewModal.classList.contains("is-open")){closePreview();}});}'
            . 'refreshSelectedButton();'
            . '})();</script>';

        return $html;
    }

    private static function renderDescriptionCell(string $description, int $shareId, int $groupIndex, int $fileIndex): string
    {
        $text = trim($description);
        if ($text === '') {
            return '<span class="uk-text-meta">-</span>';
        }

        $maxLength = 140;
        $needsToggle = mb_strlen($text) > $maxLength;
        $escapedText = htmlspecialchars($text);

        if (!$needsToggle) {
            return $escapedText;
        }

        $fullId = 'klxm-desc-full-' . $shareId . '-' . $groupIndex . '-' . $fileIndex;

        return '<div class="klxm-desc-excerpt">' . $escapedText . '</div>'
            . '<button type="button" class="uk-button uk-button-text klxm-desc-toggle" data-target="' . htmlspecialchars($fullId) . '" aria-expanded="false">Mehr</button>'
            . '<div id="' . htmlspecialchars($fullId) . '" class="klxm-desc-full" hidden>' . $escapedText . '</div>';
    }

    private static function renderLockedMessage(string $token): string
    {
        $branding = self::getShareBranding();
        $styles = self::renderShareBaseStyles($branding['accent']);
        $header = self::renderShareBrandingHeader($branding);

        if ($token === '') {
            return $styles . '<section class="uk-section uk-section-small"><div class="uk-container"><div class="uk-card uk-card-body">' . $header . '<div class="uk-alert-warning">Diese Dateiablage ist nur mit Freigabelink verfügbar.</div></div></div></section>';
        }

        return $styles . '<section class="uk-section uk-section-small"><div class="uk-container"><div class="uk-card uk-card-body">' . $header . '<div class="uk-alert-danger">Kein Zugriff auf diese Freigabe oder Freigabe abgelaufen.</div></div></div></section>';
    }

    private static function renderPasswordForm(string $token, string $error): string
    {
        $branding = self::getShareBranding();
        $action = self::buildCurrentShareUrl($token, []);

        $html = self::renderShareBaseStyles($branding['accent']);
        $html .= '<div class="uk-section uk-section-small"><div class="uk-container"><div class="uk-card uk-card-default uk-card-body">';
        $html .= self::renderShareBrandingHeader($branding);
        $html .= '<h3>Passwortgeschützte Dateiablage</h3>';
        $html .= '<p>Bitte Passwort eingeben, um die Dateien anzuzeigen.</p>';
        if ($error !== '') {
            $html .= '<div class="uk-alert-danger" uk-alert>' . htmlspecialchars($error) . '</div>';
        }
        $html .= '<form method="post" action="' . htmlspecialchars($action) . '">';
        $html .= '<div class="uk-margin"><label class="uk-form-label" for="klxm_board_share_password">Passwort</label>';
        $html .= '<div class="uk-form-controls"><input class="uk-input" id="klxm_board_share_password" type="password" name="klxm_board_share_password" required></div></div>';
        $html .= '<button type="submit" class="uk-button uk-button-primary">Freigabe öffnen</button>';
        $html .= '</form></div></div></div>';

        return $html;
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function renderRequestArea(array $share, string $token): string
    {
        rex_login::startSession();

        if ((int) ($share['request_enabled'] ?? 1) !== 1) {
            return self::renderLockedMessage($token);
        }

        $message = ['success' => '', 'error' => ''];
        if (rex_request::post('klxm_board_share_request', 'int', 0) === 1) {
            $message = self::processShareRequestForm($share);
        }

        return self::renderRequestForm($share, $message['success'], $message['error']);
    }

    /**
     * @param array<string, mixed> $share
     * @return array{success:string,error:string}
     */
    private static function processShareRequestForm(array $share): array
    {
        rex_login::startSession();

        $csrf = rex_csrf_token::factory('klxm_restricted_file_share_request');
        if (!$csrf->isValid()) {
            return ['success' => '', 'error' => 'Anfrage konnte nicht verarbeitet werden (CSRF-Token ungültig).'];
        }

        $shareId = (int) ($share['id'] ?? 0);
        if ($shareId <= 0) {
            return ['success' => '', 'error' => 'Anfrage konnte nicht verarbeitet werden.'];
        }

        if (self::isRequestHoneypotTriggered()) {
            return ['success' => 'Danke. Die Anfrage wurde erfasst. Sie erhalten eine E-Mail mit Link zur Freigabe.', 'error' => ''];
        }

        $nonce = trim(rex_request::post('request_form_nonce', 'string', ''));
        $issuedAt = rex_request::post('request_form_issued_at', 'int', 0);
        if (!self::consumeRequestGuard($shareId, $nonce, $issuedAt)) {
            return ['success' => '', 'error' => 'Das Formular wurde bereits verwendet oder ist abgelaufen. Bitte Seite neu laden und erneut senden.'];
        }

        $age = time() - $issuedAt;
        if ($issuedAt <= 0 || $age < self::REQUEST_GUARD_MIN_SECONDS || $age > self::REQUEST_GUARD_MAX_SECONDS) {
            return ['success' => '', 'error' => 'Die Anfrage konnte nicht validiert werden. Bitte Formular erneut senden.'];
        }

        $email = trim(rex_request::post('request_email', 'string', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => '', 'error' => 'Bitte eine gültige E-Mail-Adresse eingeben.'];
        }

        $emailNorm = self::normalizeEmail($email);
        if (self::hasRecentRequestForEmail($emailNorm, self::REQUEST_EXISTING_EMAIL_COOLDOWN_SECONDS)) {
            return ['success' => '', 'error' => 'Bitte kurz warten, bevor Sie eine weitere Anfrage senden.'];
        }

        $rawIp = trim((string) rex_request::server('REMOTE_ADDR', 'string', ''));
        $ipHash = self::hashIpAddress($rawIp);
        if (self::isRequestRateLimited($shareId, $email, $ipHash, self::REQUEST_RESEND_COOLDOWN_SECONDS)) {
            return ['success' => '', 'error' => 'Bitte kurz warten, bevor Sie eine weitere Anfrage senden.'];
        }

        $fields = self::decodeRequestFields($share);
        $payload = [];
        foreach ($fields as $field) {
            $fieldKey = $field['key'];
            $type = $field['type'];

            if ($type === 'checkbox') {
                $value = rex_request::post($fieldKey, 'int', 0) === 1 ? '1' : '0';
            } else {
                $value = trim(rex_request::post($fieldKey, 'string', ''));
            }

            if ($field['required'] && $type === 'checkbox' && $value !== '1') {
                return ['success' => '', 'error' => 'Bitte Feld "' . $field['label'] . '" bestätigen.'];
            }

            if ($field['required'] && $type !== 'checkbox' && $value === '') {
                return ['success' => '', 'error' => 'Bitte Feld "' . $field['label'] . '" ausfüllen.'];
            }

            $payload[$fieldKey] = $value;
        }

        $validDays = (int) ($share['request_valid_days'] ?? 3);
        if ($validDays <= 0) {
            $validDays = 3;
        }

        $accessToken = bin2hex(random_bytes(24));
        $validUntilTs = time() + ($validDays * 86400);
        $validUntil = rex_sql::datetime($validUntilTs);

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_file_share_request'));
        $sql->setValue('share_id', (int) $share['id']);
        $sql->setValue('article_id', (int) $share['article_id']);
        $sql->setValue('request_email', $email);
        $sql->setValue('request_payload', (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        $sql->setValue('access_token_hash', hash('sha256', $accessToken));
        $sql->setValue('access_token_plain', $accessToken);
        $sql->setValue('valid_until', $validUntil);
        $sql->setValue('mail_sent', 0);
        $sql->setValue('ip', null);
        $sql->setValue('ip_hash', $ipHash);
        $sql->setValue('useragent', trim((string) rex_request::server('HTTP_USER_AGENT', 'string', '')));
        $sql->setValue('createdate', rex_sql::datetime(time()));
        $sql->setValue('updatedate', rex_sql::datetime(time()));
        $sql->insert();
        $requestId = (int) $sql->getLastId();

        $articleUrl = (int) ($share['article_id'] ?? 0) > 0
            ? rtrim((string) rex::getServer(), '/') . rex_getUrl((int) $share['article_id'])
            : rtrim((string) rex::getServer(), '/') . '/index.php';
        $shareUrl = rtrim((string) rex::getServer(), '/') . self::buildShareUrl($share, $accessToken);
        $mailSent = self::sendShareRequestMail($email, $articleUrl, $shareUrl, $validDays);

        if ($mailSent) {
            rex_sql::factory()->setQuery(
                'UPDATE ' . rex::getTable('klxm_restricted_file_share_request') . ' SET mail_sent = 1, updatedate = ? WHERE id = ?',
                [rex_sql::datetime(time()), $requestId]
            );
        }

        return [
            'success' => 'Danke. Die Anfrage wurde erfasst. Sie erhalten eine E-Mail mit Link zur Freigabe.',
            'error' => '',
        ];
    }

    private static function sendShareRequestMail(string $email, string $articleUrl, string $shareUrl, int $validDays): bool
    {
        if (!class_exists(rex_mailer::class)) {
            return false;
        }

        $branding = self::getShareBranding();
        $brandTitle = trim($branding['title']);
        $brandSubtitle = trim($branding['subtitle']);
        $accent = trim($branding['accent']);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $accent = '#0f6eb8';
        }

        $mailLogoRaw = trim((string) rex_addon::get('klxm_restricted')->getConfig('share_brand_logo_mail', ''));
        $logoRaw = $mailLogoRaw !== '' ? $mailLogoRaw : trim($branding['logo']);
        $logoUrl = '';
        if ($logoRaw !== '') {
            if (preg_match('/^https?:\/\//i', $logoRaw) === 1) {
                $logoUrl = $logoRaw;
            } else {
                $logoUrl = rex_url::media($logoRaw);
            }
        }
        if ($logoUrl !== '' && preg_match('/^https?:\/\//i', $logoUrl) !== 1) {
            $logoUrl = rtrim((string) rex::getServer(), '/') . '/' . ltrim($logoUrl, '/');
        }

        $logoPath = strtolower((string) parse_url($logoUrl, PHP_URL_PATH));
        $logoExt = strtolower((string) pathinfo($logoPath, PATHINFO_EXTENSION));
        $isMailSafeLogo = in_array($logoExt, ['png', 'jpg', 'jpeg'], true);

        $footer = trim((string) rex_addon::get('klxm_restricted')->getConfig('share_request_mail_footer', ''));
        $footerBlock = $footer !== '' ? "\n\n" . $footer : '';
        $mailTitle = $brandTitle !== '' ? $brandTitle : 'Dateifreigabe';
        $mailSubtitle = $brandSubtitle !== '' ? $brandSubtitle : 'Ihr Zugriff auf die angefragten Dateien';
        $logoAlt = $brandTitle !== '' ? ($brandTitle . ' Logo') : 'Logo';
        $preheader = 'Ihr persönlicher Freigabelink ist verfügbar und ' . $validDays . ' Tage gültig.';

        $plainBody = "Vielen Dank für Ihre Anfrage.\n\n"
            . "Direkter Freigabelink:\n" . $shareUrl . "\n\n"
            . "Artikelseite:\n" . $articleUrl . "\n\n"
            . "Der Freigabelink ist " . $validDays . " Tage gültig."
            . $footerBlock;

        $footerHtml = '';
        if ($footer !== '') {
            $footerHtml = '<tr><td style="padding:0 32px 20px 32px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:#5f7286;">'
                . nl2br(htmlspecialchars($footer), false)
                . '</td></tr>';
        }

        $logoHtml = '';
        if ($logoUrl !== '' && $isMailSafeLogo) {
            $logoHtml = '<img src="' . htmlspecialchars($logoUrl) . '" alt="' . htmlspecialchars($logoAlt) . '" width="180" style="display:block;max-width:180px;width:100%;height:auto;border:0;outline:none;text-decoration:none;margin:0 auto 14px auto;">';
        }

        $htmlBody = '<!doctype html>'
            . '<html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f3f6fb;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . htmlspecialchars($preheader) . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f3f6fb;">'
            . '<tr><td align="center" style="padding:28px 12px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="620" style="width:620px;max-width:620px;background:#ffffff;border:1px solid #d8e0ea;border-radius:8px;">'
            . '<tr><td style="padding:30px 32px 16px 32px;text-align:center;">'
            . $logoHtml
            . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:' . htmlspecialchars($accent) . ';font-weight:bold;">' . htmlspecialchars($mailTitle) . '</div>'
            . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#5f7286;margin-top:6px;">' . htmlspecialchars($mailSubtitle) . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:6px 32px 0 32px;font-family:Arial,Helvetica,sans-serif;color:#12202f;">'
            . '<h1 style="margin:0 0 12px 0;font-size:24px;line-height:1.25;font-weight:700;">Ihre Anfrage wurde bestätigt</h1>'
            . '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.6;">Vielen Dank für Ihre Anfrage. Über den folgenden Button können Sie die Dateifreigabe direkt öffnen.</p>'
            . '</td></tr>'
            . '<tr><td align="center" style="padding:10px 32px 18px 32px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td bgcolor="' . htmlspecialchars($accent) . '" style="border-radius:6px;">'
            . '<a href="' . htmlspecialchars($shareUrl) . '" style="display:inline-block;padding:12px 22px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">Freigabe öffnen</a>'
            . '</td></tr></table>'
            . '</td></tr>'
            . '<tr><td style="padding:0 32px 18px 32px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#12202f;">'
            . '<p style="margin:0 0 12px 0;"><strong>Gültigkeit:</strong> ' . (int) $validDays . ' Tage</p>'
            . '<p style="margin:0 0 8px 0;"><strong>Direkter Freigabelink:</strong><br><a href="' . htmlspecialchars($shareUrl) . '" style="color:' . htmlspecialchars($accent) . ';word-break:break-all;">' . htmlspecialchars($shareUrl) . '</a></p>'
            . '<p style="margin:0;"><strong>Artikelseite:</strong><br><a href="' . htmlspecialchars($articleUrl) . '" style="color:' . htmlspecialchars($accent) . ';word-break:break-all;">' . htmlspecialchars($articleUrl) . '</a></p>'
            . '</td></tr>'
            . '<tr><td style="padding:14px 32px 16px 32px;border-top:1px solid #d8e0ea;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#7a8da1;">'
            . 'Wenn der Button in Ihrem Mailprogramm nicht funktioniert, kopieren Sie den direkten Freigabelink in die Adresszeile Ihres Browsers.'
            . '</td></tr>'
            . $footerHtml
            . '</table>'
            . '</td></tr></table>'
            . '</body></html>';

        try {
            $mailer = new rex_mailer();
            $mailer->addAddress($email);
            $mailer->Subject = 'Ihre Anfrage zur Dateifreigabe';
            $mailer->Body = $htmlBody;
            $mailer->AltBody = $plainBody;
            $mailer->isHTML(true);

            return (bool) $mailer->send();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function renderRequestForm(array $share, string $success, string $error): string
    {
        $branding = self::getShareBranding();
        $fields = self::decodeRequestFields($share);
        $csrf = rex_csrf_token::factory('klxm_restricted_file_share_request');
        $shareId = (int) ($share['id'] ?? 0);
        $guard = self::createRequestGuard($shareId);
        $introText = trim((string) ($share['request_intro_text'] ?? ''));
        if ($introText === '') {
            $introText = 'Bitte Formular ausfüllen. Danach senden wir den Freigabelink per E-Mail.';
        }

        $html = self::renderShareBaseStyles($branding['accent']);
        $html .= '<section class="uk-section uk-section-small"><div class="uk-container"><div class="uk-card uk-card-default uk-card-body">';
        $html .= self::renderShareBrandingHeader($branding);
        $html .= '<h3>Freigabe anfragen</h3>';
        $html .= '<p>' . nl2br(htmlspecialchars($introText)) . '</p>';
        if ($success !== '') {
            $html .= '<div class="uk-alert-success" uk-alert>' . htmlspecialchars($success) . '</div>';
        }
        if ($error !== '') {
            $html .= '<div class="uk-alert-danger" uk-alert>' . htmlspecialchars($error) . '</div>';
        }

        $html .= '<form method="post" action="">';
        $html .= '<input type="hidden" name="klxm_board_share_request" value="1">';
        $html .= '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($csrf->getValue()) . '">';
        $html .= '<input type="hidden" name="request_form_nonce" value="' . htmlspecialchars($guard['nonce']) . '">';
        $html .= '<input type="hidden" name="request_form_issued_at" value="' . (int) $guard['issued_at'] . '">';
        $html .= '<div style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">';
        $html .= '<label for="request_hp_website">Website</label>';
        $html .= '<input id="request_hp_website" type="text" name="request_hp_website" tabindex="-1" autocomplete="off">';
        $html .= '<label for="request_hp_company">Firma</label>';
        $html .= '<input id="request_hp_company" type="text" name="request_hp_company" tabindex="-1" autocomplete="off">';
        $html .= '</div>';

        $html .= '<div class="uk-margin">';
        $html .= '<label class="uk-form-label" for="request_email">E-Mail-Adresse *</label>';
        $html .= '<div class="uk-form-controls"><input class="uk-input" id="request_email" type="email" name="request_email" required></div>';
        $html .= '</div>';

        foreach ($fields as $field) {
            $fieldId = 'req_' . $field['key'];
            $requiredAttr = $field['required'] ? ' required' : '';
            $requiredMark = $field['required'] ? ' *' : '';

            $html .= '<div class="uk-margin">';
            $html .= '<label class="uk-form-label" for="' . htmlspecialchars($fieldId) . '">' . htmlspecialchars($field['label']) . $requiredMark . '</label>';
            $html .= '<div class="uk-form-controls">';

            if ($field['type'] === 'textarea') {
                $html .= '<textarea class="uk-textarea" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($field['key']) . '"' . $requiredAttr . '></textarea>';
            } elseif ($field['type'] === 'checkbox') {
                $html .= '<label><input class="uk-checkbox" type="checkbox" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($field['key']) . '" value="1"> ' . htmlspecialchars($field['label']) . '</label>';
            } elseif ($field['type'] === 'select') {
                $html .= '<select class="uk-select" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($field['key']) . '"' . $requiredAttr . '>';
                $html .= '<option value="">Bitte wählen</option>';
                foreach ($field['options'] as $option) {
                    $html .= '<option value="' . htmlspecialchars($option) . '">' . htmlspecialchars($option) . '</option>';
                }
                $html .= '</select>';
            } else {
                $html .= '<input class="uk-input" id="' . htmlspecialchars($fieldId) . '" type="text" name="' . htmlspecialchars($field['key']) . '"' . $requiredAttr . '>';
            }

            $html .= '</div></div>';
        }

        $html .= '<button type="submit" class="uk-button uk-button-primary">Freigabe anfragen</button>';
        $html .= '</form></div></div></section>';

        return $html;
    }

    /**
     * @return array{nonce:string,issued_at:int}
     */
    private static function createRequestGuard(int $shareId): array
    {
        rex_login::startSession();

        if ($shareId <= 0) {
            return ['nonce' => '', 'issued_at' => time()];
        }

        $nonce = bin2hex(random_bytes(16));
        $issuedAt = time();
        $payload = (string) json_encode([
            'nonce' => $nonce,
            'issued_at' => $issuedAt,
        ], JSON_UNESCAPED_UNICODE);

        rex_set_session(self::getRequestGuardSessionKey($shareId), $payload);

        return [
            'nonce' => $nonce,
            'issued_at' => $issuedAt,
        ];
    }

    private static function consumeRequestGuard(int $shareId, string $nonce, int $issuedAt): bool
    {
        if ($shareId <= 0 || $nonce === '' || $issuedAt <= 0) {
            return false;
        }

        $sessionKey = self::getRequestGuardSessionKey($shareId);
        $raw = rex_session($sessionKey, 'string', '');
        if ($raw === '') {
            return false;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            rex_unset_session($sessionKey);
            return false;
        }

        $expectedNonce = trim((string) ($decoded['nonce'] ?? ''));
        $expectedIssuedAt = (int) ($decoded['issued_at'] ?? 0);
        if ($expectedNonce === '' || $expectedIssuedAt <= 0) {
            rex_unset_session($sessionKey);
            return false;
        }

        if (!hash_equals($expectedNonce, $nonce) || $expectedIssuedAt !== $issuedAt) {
            return false;
        }

        rex_unset_session($sessionKey);
        return true;
    }

    private static function getRequestGuardSessionKey(int $shareId): string
    {
        return 'klxm_restricted_share_request_guard_' . $shareId;
    }

    private static function isRequestHoneypotTriggered(): bool
    {
        $website = \rex_post('request_hp_website', 'string', null);
        $company = \rex_post('request_hp_company', 'string', null);
        $legacyWebsite = \rex_post('request_website', 'string', null);
        $legacyCompany = \rex_post('request_company', 'string', null);

        $hasNewHoneypots = $website !== null && $company !== null;
        $hasLegacyHoneypots = $legacyWebsite !== null && $legacyCompany !== null;

        // Missing honeypot fields usually indicate scripted submissions.
        if (!$hasNewHoneypots && !$hasLegacyHoneypots) {
            return true;
        }

        if ($hasNewHoneypots && (trim((string) $website) !== '' || trim((string) $company) !== '')) {
            return true;
        }

        if ($hasLegacyHoneypots && (trim((string) $legacyWebsite) !== '' || trim((string) $legacyCompany) !== '')) {
            return true;
        }

        return false;
    }

    private static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function hasRecentRequestForEmail(string $emailNorm, int $cooldownSeconds): bool
    {
        if ($emailNorm === '') {
            return false;
        }

        $seconds = max(30, $cooldownSeconds);
        $threshold = rex_sql::datetime(time() - $seconds);

        $rows = rex_sql::factory()->getArray(
            'SELECT id FROM ' . rex::getTable('klxm_restricted_file_share_request') . ' WHERE createdate >= ? AND LOWER(request_email) = ? LIMIT 1',
            [$threshold, $emailNorm]
        );

        return $rows !== [];
    }

    private static function isRequestRateLimited(int $shareId, string $email, ?string $ipHash, int $cooldownSeconds): bool
    {
        $seconds = max(10, $cooldownSeconds);
        $threshold = rex_sql::datetime(time() - $seconds);
        $emailNorm = self::normalizeEmail($email);

        $sql = 'SELECT id FROM ' . rex::getTable('klxm_restricted_file_share_request')
            . ' WHERE share_id = ? AND createdate >= ? AND (LOWER(request_email) = ?';
        $params = [$shareId, $threshold, $emailNorm];

        if ($ipHash !== null && $ipHash !== '') {
            $sql .= ' OR ip_hash = ?';
            $params[] = $ipHash;
        }

        $sql .= ') LIMIT 1';

        $rows = rex_sql::factory()->getArray($sql, $params);
        return $rows !== [];
    }

    /**
     * @param array<string, mixed> $share
     * @return array<int, array{key:string,label:string,type:string,required:bool,options:array<int,string>}>
     */
    private static function decodeRequestFields(array $share): array
    {
        $raw = (string) ($share['request_form_json'] ?? '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $allowedTypes = ['text', 'textarea', 'checkbox', 'select'];
        $fields = [];
        foreach ($decoded as $field) {
            if (!is_array($field)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            $label = trim((string) ($field['label'] ?? ''));
            $type = trim((string) ($field['type'] ?? 'text'));

            if ($key === '' || $label === '' || !in_array($type, $allowedTypes, true)) {
                continue;
            }

            $options = [];
            if ($type === 'select') {
                $rawOptions = (string) ($field['options'] ?? '');
                foreach (explode('|', $rawOptions) as $opt) {
                    $opt = trim($opt);
                    if ($opt !== '') {
                        $options[] = $opt;
                    }
                }
            }

            $fields[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => (int) ($field['required'] ?? 0) === 1,
                'options' => $options,
            ];
        }

        return $fields;
    }

    /**
     * @param array<int, array{key:string,label:string,type:string,required:int,options:string}> $requestFields
     */
    private static function encodeRequestFields(array $requestFields): string
    {
        if ($requestFields === []) {
            return '[]';
        }

        return (string) json_encode($requestFields, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function isAccessAllowed(array $share, bool $passwordUnlocked = false): bool
    {
        if (self::isRedaxoUserLoggedIn()) {
            return true;
        }

        if (self::isExpired($share)) {
            return false;
        }

        if (self::hasDownloadLimitReached($share)) {
            return false;
        }

        $requiresPassword = (string) ($share['password_hash'] ?? '') !== '';
        if ($requiresPassword && !$passwordUnlocked) {
            return false;
        }

        return true;
    }

    private static function isRedaxoUserLoggedIn(): bool
    {
        if (rex_backend_login::createUser() !== null) {
            return true;
        }

        $auth = new Auth();
        return $auth->isLoggedIn();
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

    /**
     * @param array<string, mixed> $share
     */
    private static function downloadSingleFile(array $share, string $filename, ?int $requestId = null): never
    {
        if ($filename === '' || basename($filename) !== $filename || str_contains($filename, '/') || str_contains($filename, '\\')) {
            self::sendText('Ungültiger Dateiname.', rex_response::HTTP_BAD_REQUEST);
        }

        $allowed = self::collectAllowedFilenames($share);
        if (!in_array($filename, $allowed, true)) {
            self::sendText('Datei ist nicht Teil der Freigabe.', rex_response::HTTP_FORBIDDEN);
        }

        $path = rex_path::media($filename);
        if (!is_file($path)) {
            self::sendText('Datei nicht gefunden.', rex_response::HTTP_NOT_FOUND);
        }

        self::increaseDownloadCount((int) $share['id']);
        self::recordFileDownloadEvents((int) $share['id'], (int) ($share['article_id'] ?? 0), [$filename], 'file', $requestId);
        rex_response::sendFile($path, 'application/octet-stream', 'attachment', $filename);
        exit;
    }

    /**
     * @param array<string, mixed> $share
     * @param string[] $filenames
     */
    private static function downloadZip(array $share, array $filenames, string $downloadMode = 'zip_all', ?int $requestId = null): never
    {
        if ((int) ($share['allow_zip'] ?? 0) !== 1) {
            self::sendText('ZIP-Download deaktiviert.', rex_response::HTTP_FORBIDDEN);
        }

        if ($filenames === []) {
            self::sendText('Keine Dateien verfügbar.', rex_response::HTTP_NOT_FOUND);
        }

        $zipDir = rex_path::addonCache('klxm_restricted', 'file-shares/');
        rex_dir::create($zipDir);

        $zipPath = $zipDir . 'file-share-' . (int) $share['id'] . '-' . bin2hex(random_bytes(8)) . '.zip';

        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            self::sendText('ZIP konnte nicht erstellt werden.', rex_response::HTTP_INTERNAL_ERROR);
        }

        $zipEntries = self::buildZipEntriesByGroup($share, $filenames);
        if ($zipEntries === []) {
            self::sendText('Keine Dateien verfügbar.', rex_response::HTTP_NOT_FOUND);
        }

        foreach ($zipEntries as $zipEntry) {
            $filename = $zipEntry['filename'];
            $entryName = $zipEntry['entry_name'];
            $path = rex_path::media($filename);
            if (!is_file($path)) {
                continue;
            }

            $zip->addFile($path, $entryName);
        }

        $zip->close();

        register_shutdown_function(static function () use ($zipPath): void {
            rex_file::delete($zipPath);
        });

        self::increaseDownloadCount((int) $share['id']);
        self::recordFileDownloadEvents((int) $share['id'], (int) ($share['article_id'] ?? 0), $filenames, $downloadMode, $requestId);
        rex_response::sendFile($zipPath, 'application/zip', 'attachment', self::buildZipDownloadFilename($share));
        exit;
    }

    /**
     * @param string[] $filenames
     */
    private static function recordFileDownloadEvents(int $shareId, int $articleId, array $filenames, string $downloadMode, ?int $requestId): void
    {
        if ($filenames === []) {
            return;
        }

        $timestamp = rex_sql::datetime(time());
        $sql = rex_sql::factory();
        foreach ($filenames as $filename) {
            if (!is_string($filename) || $filename === '') {
                continue;
            }

            $sql->setTable(rex::getTable('klxm_restricted_file_share_download'));
            $sql->setValue('share_id', $shareId);
            $sql->setValue('article_id', $articleId);
            $sql->setValue('request_id', $requestId);
            $sql->setValue('filename', $filename);
            $sql->setValue('download_mode', $downloadMode);
            $sql->setValue('createdate', $timestamp);
            $sql->insert();
        }
    }

    private static function hashIpAddress(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }

        $salt = (string) rex_addon::get('klxm_restricted')->getConfig('request_ip_hash_salt', '');
        if ($salt === '') {
            $salt = 'klxm-restricted-default-salt';
        }

        return hash('sha256', $salt . '|' . $ip);
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

    /**
     * @param array<string, mixed> $share
     * @param string[] $filenames
     * @return array<int, array{filename:string,entry_name:string}>
     */
    private static function buildZipEntriesByGroup(array $share, array $filenames): array
    {
        if ($filenames === []) {
            return [];
        }

        $selected = [];
        foreach ($filenames as $filename) {
            if (!is_string($filename) || $filename === '') {
                continue;
            }
            if (!in_array($filename, $selected, true)) {
                $selected[] = $filename;
            }
        }

        if ($selected === []) {
            return [];
        }

        $selectedLookup = array_fill_keys($selected, true);
        $assignedLookup = [];
        $entries = [];
        $usedEntries = [];

        foreach (self::getDisplayGroups($share) as $group) {
            $groupName = trim((string) ($group['name'] ?? 'Allgemein'));
            if ($groupName === '') {
                $groupName = 'Allgemein';
            }
            $folder = self::sanitizeZipPathSegment($groupName);

            foreach ($group['files'] as $file) {
                $filename = (string) ($file['filename'] ?? '');
                if ($filename === '' || !isset($selectedLookup[$filename])) {
                    continue;
                }

                $baseName = rex_path::basename($filename);
                $candidate = $folder . '/' . $baseName;

                if (in_array($candidate, $usedEntries, true)) {
                    $nameWithoutExt = pathinfo($baseName, PATHINFO_FILENAME);
                    $ext = pathinfo($baseName, PATHINFO_EXTENSION);
                    $i = 1;
                    do {
                        $next = $nameWithoutExt . '-' . $i;
                        if ($ext !== '') {
                            $next .= '.' . $ext;
                        }
                        $candidate = $folder . '/' . $next;
                        $i++;
                    } while (in_array($candidate, $usedEntries, true));
                }

                $entries[] = [
                    'filename' => $filename,
                    'entry_name' => $candidate,
                ];
                $usedEntries[] = $candidate;
                $assignedLookup[$filename] = true;
            }
        }

        foreach ($selected as $filename) {
            if (isset($assignedLookup[$filename])) {
                continue;
            }

            $folder = 'Allgemein';
            $baseName = rex_path::basename($filename);
            $candidate = $folder . '/' . $baseName;

            if (in_array($candidate, $usedEntries, true)) {
                $nameWithoutExt = pathinfo($baseName, PATHINFO_FILENAME);
                $ext = pathinfo($baseName, PATHINFO_EXTENSION);
                $i = 1;
                do {
                    $next = $nameWithoutExt . '-' . $i;
                    if ($ext !== '') {
                        $next .= '.' . $ext;
                    }
                    $candidate = $folder . '/' . $next;
                    $i++;
                } while (in_array($candidate, $usedEntries, true));
            }

            $entries[] = [
                'filename' => $filename,
                'entry_name' => $candidate,
            ];
            $usedEntries[] = $candidate;
        }

        return $entries;
    }

    private static function sanitizeZipPathSegment(string $segment): string
    {
        $clean = trim($segment);
        $clean = str_replace(['\\', '/'], '-', $clean);
        $clean = preg_replace('/\s+/', ' ', (string) $clean);
        $clean = preg_replace('/[^\p{L}\p{N} ._()-]/u', '-', (string) $clean);
        $clean = trim((string) $clean, " .-_");

        if ($clean === '') {
            return 'Allgemein';
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $share
     * @return string[]
     */
    private static function collectAllowedFilenames(array $share): array
    {
        $groups = self::getDisplayGroups($share);
        $files = [];

        foreach ($groups as $group) {
            foreach ($group['files'] as $file) {
                $files[] = $file['filename'];
            }
        }

        return array_values(array_unique($files));
    }

    private static function increaseDownloadCount(int $shareId): void
    {
        rex_sql::factory()->setQuery(
            'UPDATE ' . rex::getTable('klxm_restricted_file_share')
            . ' SET download_count = download_count + 1, last_download = ?, updatedate = ? WHERE id = ?',
            [
                rex_sql::datetime(time()),
                rex_sql::datetime(time()),
                $shareId,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findByToken(string $token): ?array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('klxm_restricted_file_share') . ' WHERE token_hash = ? LIMIT 1',
            [hash('sha256', $token)]
        );

        if ($rows === []) {
            // Backward compatibility: tolerate legacy/migrated rows where token_hash may be out of sync.
            $rows = rex_sql::factory()->getArray(
                'SELECT * FROM ' . rex::getTable('klxm_restricted_file_share') . ' WHERE token_plain = ? LIMIT 1',
                [$token]
            );
        }

        return $rows[0] ?? null;
    }

    /**
     * @return array{share:?array,password_unlocked:bool,request_id:?int}
     */
    private static function resolveGuestAccessContext(string $token, ?int $articleId = null): array
    {
        $share = self::findByToken($token);
        if ($share !== null) {
            $mode = (string) ($share['share_mode'] ?? 'article');
            if ($mode !== 'direct' && $articleId !== null && (int) ($share['article_id'] ?? 0) !== $articleId) {
                return ['share' => null, 'password_unlocked' => false, 'request_id' => null];
            }

            return ['share' => $share, 'password_unlocked' => false, 'request_id' => null];
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT share.*, req.id AS request_id FROM ' . rex::getTable('klxm_restricted_file_share_request') . ' req '
            . 'INNER JOIN ' . rex::getTable('klxm_restricted_file_share') . ' share ON share.id = req.share_id '
            . 'WHERE req.access_token_hash = ? AND req.valid_until >= ? AND share.status = 1 LIMIT 1',
            [
                hash('sha256', $token),
                rex_sql::datetime(time()),
            ]
        );

        $share = $rows[0] ?? null;
        if (!is_array($share)) {
            return ['share' => null, 'password_unlocked' => false, 'request_id' => null];
        }

        $mode = (string) ($share['share_mode'] ?? 'article');
        if ($mode !== 'direct' && $articleId !== null && (int) ($share['article_id'] ?? 0) !== $articleId) {
            return ['share' => null, 'password_unlocked' => false, 'request_id' => null];
        }

        return [
            'share' => $share,
            'password_unlocked' => true,
            'request_id' => isset($share['request_id']) ? (int) $share['request_id'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findLatestShareForArticle(int $articleId): ?array
    {
        $rows = rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('klxm_restricted_file_share') . ' WHERE (share_mode = ? OR share_mode IS NULL OR share_mode = \'\') AND article_id = ? AND status = 1 ORDER BY id DESC LIMIT 1',
            ['article', $articleId]
        );

        return $rows[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function getActiveArticleShares(int $articleId): array
    {
        if ($articleId <= 0) {
            return [];
        }

        return rex_sql::factory()->getArray(
            'SELECT id, title FROM ' . rex::getTable('klxm_restricted_file_share') . ' WHERE (share_mode = ? OR share_mode IS NULL OR share_mode = \'\') AND article_id = ? AND status = 1 ORDER BY id DESC',
            ['article', $articleId]
        );
    }

    public static function handleFrontendDirectShareRequest(): bool
    {
        $token = trim(rex_request::get('klxm_board_share', 'string', ''));
        if ($token === '') {
            return false;
        }

        $downloadMode = trim(rex_request::get('klxm_board_share_download', 'string', ''));
        if ($downloadMode === '') {
            $downloadMode = trim(rex_request::post('klxm_board_share_download', 'string', ''));
        }
        if ($downloadMode !== '') {
            return false;
        }

        $access = self::resolveGuestAccessContext($token, null);
        $share = $access['share'];
        if ($share === null || (string) ($share['share_mode'] ?? 'article') !== 'direct') {
            return false;
        }

        rex_login::startSession();

        if (self::isRedaxoUserLoggedIn()) {
            $filesByGroup = self::getDisplayGroups($share);
            if ($filesByGroup === []) {
                self::sendText('Aktuell sind keine Dateien verfügbar.', rex_response::HTTP_OK);
            }

            $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dateifreigabe</title></head><body>';
            $html .= self::renderShareList($share, $token, $filesByGroup);
            $html .= '</body></html>';
            rex_response::cleanOutputBuffers();
            rex_response::sendContent($html, 'text/html; charset=utf-8');
            exit;
        }

        $passwordUnlocked = $access['password_unlocked'];
        $sessionKey = 'klxm_restricted_file_share_auth_' . (int) $share['id'];
        $requiresPassword = (string) ($share['password_hash'] ?? '') !== '';
        $passwordUnlocked = $passwordUnlocked || rex_session($sessionKey, 'int', 0) === 1;

        if ($requiresPassword && !$passwordUnlocked) {
            $submittedPassword = rex_request::post('klxm_board_share_password', 'string', '');
            if ($submittedPassword !== '' && password_verify($submittedPassword, (string) $share['password_hash'])) {
                rex_set_session($sessionKey, 1);
                $passwordUnlocked = true;
            }
        }

        if (!self::isAccessAllowed($share, $passwordUnlocked)) {
            self::sendText('Kein Zugriff auf diese Freigabe.', rex_response::HTTP_FORBIDDEN);
        }

        if ($requiresPassword && !$passwordUnlocked) {
            $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dateifreigabe</title></head><body>';
            $html .= self::renderPasswordForm($token, '');
            $html .= '</body></html>';
            rex_response::cleanOutputBuffers();
            rex_response::sendContent($html, 'text/html; charset=utf-8');
            exit;
        }

        $filesByGroup = self::getDisplayGroups($share);
        if ($filesByGroup === []) {
            self::sendText('Aktuell sind keine Dateien verfügbar.', rex_response::HTTP_OK);
        }

        $html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dateifreigabe</title></head><body>';
        $html .= self::renderShareList($share, $token, $filesByGroup);
        $html .= '</body></html>';
        rex_response::cleanOutputBuffers();
        rex_response::sendContent($html, 'text/html; charset=utf-8');
        exit;
    }

    /**
     * @param array<string, mixed> $share
     * @param string[] $selectedFiles
     */
    private static function createAsyncZipJob(array $share, array $selectedFiles, string $downloadMode, ?int $requestId): string
    {
        self::cleanupExpiredZipJobs();

        $jobToken = bin2hex(random_bytes(20));
        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_file_share_zip_job'));
        $sql->setValue('job_token', $jobToken);
        $sql->setValue('share_id', (int) $share['id']);
        $sql->setValue('article_id', (int) ($share['article_id'] ?? 0));
        $sql->setValue('request_id', $requestId);
        $sql->setValue('download_mode', $downloadMode);
        $sql->setValue('selected_files', (string) json_encode(array_values($selectedFiles), JSON_UNESCAPED_UNICODE));
        $sql->setValue('status', 'queued');
        $sql->setValue('zip_path', null);
        $sql->setValue('error_message', null);
        $sql->setValue('expires_at', rex_sql::datetime(time() + 3600));
        $sql->setValue('createdate', rex_sql::datetime(time()));
        $sql->setValue('updatedate', rex_sql::datetime(time()));
        $sql->insert();

        return $jobToken;
    }

    /**
     * @param array<string, mixed> $share
     * @return array<string, mixed>
     */
    private static function getAsyncZipJobStatus(string $jobToken, array $share, string $shareToken): array
    {
        self::cleanupExpiredZipJobs();

        $jobs = rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' WHERE job_token = ? AND share_id = ? LIMIT 1',
            [$jobToken, (int) $share['id']]
        );
        $job = $jobs[0] ?? null;
        if (!is_array($job)) {
            return ['ok' => false, 'message' => 'ZIP-Job nicht gefunden.'];
        }

        $status = (string) ($job['status'] ?? 'queued');
        if ($status === 'queued') {
            self::processAsyncZipJob($job);
            $jobs = rex_sql::factory()->getArray(
                'SELECT * FROM ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' WHERE job_token = ? LIMIT 1',
                [$jobToken]
            );
            $job = $jobs[0] ?? $job;
            $status = (string) ($job['status'] ?? 'queued');
        }

        if ($status === 'ready') {
            return [
                'ok' => true,
                'status' => 'ready',
                'download_url' => self::buildCurrentShareUrl($shareToken, [
                    'klxm_board_share_download' => 'zip_async_fetch',
                    'zip_job' => $jobToken,
                ]),
            ];
        }

        if ($status === 'error') {
            return [
                'ok' => true,
                'status' => 'error',
                'message' => (string) ($job['error_message'] ?? 'ZIP konnte nicht erstellt werden.'),
            ];
        }

        return ['ok' => true, 'status' => $status];
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function processAsyncZipJob(array $job): void
    {
        $jobId = (int) $job['id'];
        $shareId = (int) ($job['share_id'] ?? 0);
        rex_sql::factory()->setQuery(
            'UPDATE ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' SET status = ?, updatedate = ? WHERE id = ?',
            ['processing', rex_sql::datetime(time()), $jobId]
        );

        $share = self::getShareById($shareId);
        if ($share === null) {
            rex_sql::factory()->setQuery(
                'UPDATE ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' SET status = ?, error_message = ?, updatedate = ? WHERE id = ?',
                ['error', 'Freigabe nicht gefunden.', rex_sql::datetime(time()), $jobId]
            );
            return;
        }

        $rawFiles = (string) ($job['selected_files'] ?? '[]');
        $decoded = json_decode($rawFiles, true);
        $files = [];
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_string($item) && $item !== '') {
                    $files[] = $item;
                }
            }
        }

        if ($files === []) {
            rex_sql::factory()->setQuery(
                'UPDATE ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' SET status = ?, error_message = ?, updatedate = ? WHERE id = ?',
                ['error', 'Keine Dateien vorhanden.', rex_sql::datetime(time()), $jobId]
            );
            return;
        }

        $zipDir = rex_path::addonCache('klxm_restricted', 'file-share-jobs/');
        rex_dir::create($zipDir);
        $zipPath = $zipDir . 'job-' . $jobId . '-' . bin2hex(random_bytes(6)) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            rex_sql::factory()->setQuery(
                'UPDATE ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' SET status = ?, error_message = ?, updatedate = ? WHERE id = ?',
                ['error', 'ZIP konnte nicht erstellt werden.', rex_sql::datetime(time()), $jobId]
            );
            return;
        }

        $zipEntries = self::buildZipEntriesByGroup($share, $files);
        if ($zipEntries === []) {
            rex_sql::factory()->setQuery(
                'UPDATE ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' SET status = ?, error_message = ?, updatedate = ? WHERE id = ?',
                ['error', 'Keine Dateien vorhanden.', rex_sql::datetime(time()), $jobId]
            );
            $zip->close();
            return;
        }

        foreach ($zipEntries as $zipEntry) {
            $filename = $zipEntry['filename'];
            $entryName = $zipEntry['entry_name'];
            $path = rex_path::media($filename);
            if (!is_file($path)) {
                continue;
            }

            $zip->addFile($path, $entryName);
        }
        $zip->close();

        rex_sql::factory()->setQuery(
            'UPDATE ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' SET status = ?, zip_path = ?, updatedate = ? WHERE id = ?',
            ['ready', $zipPath, rex_sql::datetime(time()), $jobId]
        );
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function sendAsyncZipFile(string $jobToken, array $share): never
    {
        self::cleanupExpiredZipJobs();

        $jobs = rex_sql::factory()->getArray(
            'SELECT * FROM ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' WHERE job_token = ? AND share_id = ? LIMIT 1',
            [$jobToken, (int) $share['id']]
        );
        $job = $jobs[0] ?? null;
        if (!is_array($job) || (string) ($job['status'] ?? '') !== 'ready') {
            self::sendText('ZIP ist noch nicht bereit.', rex_response::HTTP_BAD_REQUEST);
        }

        $zipPath = (string) ($job['zip_path'] ?? '');
        if ($zipPath === '' || !is_file($zipPath)) {
            self::sendText('ZIP-Datei nicht gefunden.', rex_response::HTTP_NOT_FOUND);
        }

        $rawFiles = (string) ($job['selected_files'] ?? '[]');
        $decoded = json_decode($rawFiles, true);
        $filenames = [];
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_string($item) && $item !== '') {
                    $filenames[] = $item;
                }
            }
        }

        self::increaseDownloadCount((int) $share['id']);
        self::recordFileDownloadEvents(
            (int) $share['id'],
            (int) ($share['article_id'] ?? 0),
            $filenames,
            (string) ($job['download_mode'] ?? 'zip_all'),
            isset($job['request_id']) ? (int) $job['request_id'] : null
        );

        $jobId = (int) ($job['id'] ?? 0);
        if ($jobId > 0) {
            rex_sql::factory()->setQuery(
                'DELETE FROM ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' WHERE id = ?',
                [$jobId]
            );
        }

        register_shutdown_function(static function () use ($zipPath): void {
            rex_file::delete($zipPath);
        });

        rex_response::sendFile($zipPath, 'application/zip', 'attachment', self::buildZipDownloadFilename($share));
        exit;
    }

    /**
     * @param array<string, mixed> $share
     * @param array<string, string|int> $params
     */
    public static function buildShareUrl(array $share, string $token, array $params = []): string
    {
        $query = array_merge(['klxm_board_share' => $token], $params);
        $mode = (string) ($share['share_mode'] ?? 'article');
        $articleId = (int) ($share['article_id'] ?? 0);

        if ($mode === 'direct' || $articleId <= 0) {
            $path = self::normalizeFrontendPath(rex_url::frontendController([], false));
            return $path . '?' . http_build_query($query);
        }

        return self::buildArticleSharePath($articleId, $query);
    }

    public static function cleanupExpiredZipJobs(int $limit = 300): int
    {
        $maxRows = max(20, min(2000, $limit));
        $now = rex_sql::datetime(time());

        $rows = rex_sql::factory()->getArray(
            'SELECT id, zip_path FROM ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' WHERE expires_at <= ? ORDER BY id ASC LIMIT ' . $maxRows,
            [$now]
        );

        if ($rows === []) {
            return 0;
        }

        $ids = [];
        foreach ($rows as $row) {
            $jobId = (int) ($row['id'] ?? 0);
            if ($jobId <= 0) {
                continue;
            }

            $zipPath = trim((string) ($row['zip_path'] ?? ''));
            if ($zipPath !== '' && is_file($zipPath)) {
                rex_file::delete($zipPath);
            }

            $ids[] = $jobId;
        }

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        rex_sql::factory()->setQuery(
            'DELETE FROM ' . rex::getTable('klxm_restricted_file_share_zip_job') . ' WHERE id IN (' . $placeholders . ')',
            $ids
        );

        return count($ids);
    }

    /**
     * @param array<string, string|int> $params
     */
    private static function buildCurrentShareUrl(string $token, array $params = []): string
    {
        $path = (string) parse_url((string) rex_request::server('REQUEST_URI', 'string', ''), PHP_URL_PATH);
        if ($path === '') {
            $path = '/index.php';
        }

        return $path . '?' . http_build_query(array_merge(['klxm_board_share' => $token], $params));
    }

    /**
     * @param array<string, string|int> $params
     */
    public static function buildPublicShareUrl(string $shareMode, int $articleId, string $token, array $params = []): string
    {
        $query = array_merge(['klxm_board_share' => $token], $params);
        if ($shareMode === 'direct' || $articleId <= 0) {
            $path = self::normalizeFrontendPath(rex_url::frontendController([], false));

            return $path . '?' . http_build_query($query);
        }

        return self::buildArticleSharePath($articleId, $query);
    }

    /**
     * @param array<string, string|int> $query
     */
    private static function buildArticleSharePath(int $articleId, array $query): string
    {
        if (function_exists('rex_getUrl')) {
            $url = (string) rex_getUrl($articleId, rex_clang::getCurrentId(), $query, '&');
            // rex_getUrl can contain HTML entities (e.g. &amp;). Decode once here to avoid double-encoding in attributes.
            return html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $path = (string) parse_url((string) rex_request::server('REQUEST_URI', 'string', ''), PHP_URL_PATH);
        if ($path === '') {
            $path = self::normalizeFrontendPath(rex_url::frontendController([], false));
        }

        return $path . '?' . http_build_query($query);
    }

    private static function normalizeFrontendPath(string $path): string
    {
        $cleanPath = trim($path);
        if ($cleanPath === '') {
            return '/index.php';
        }

        // rex_url::frontendController([], false) can return relative paths like "../index.php" in backend context.
        $cleanPath = ltrim($cleanPath, './');
        if ($cleanPath === '') {
            return '/index.php';
        }

        return '/' . ltrim($cleanPath, '/');
    }

    /**
     * @param array{filename:string,title:string,description:string,updatedate:string,filesize:int} $file
     */
    private static function renderFilePreview(array $share, string $token, array $file, string $displayName): string
    {
        $filename = (string) $file['filename'];
        $extension = strtolower(rex_file::extension($filename));
        $previewUrl = self::buildShareUrl($share, $token, [
            'klxm_board_share_download' => 'preview',
            'file' => $filename,
        ]);
        $caption = $displayName . ' (' . $filename . ')';

        if (self::isImageExtension($extension)) {
            $thumb = '<img class="klxm-preview-thumb" src="' . htmlspecialchars($previewUrl) . '" alt="' . htmlspecialchars($displayName) . '" loading="lazy" decoding="async">';
            return '<a href="#" class="klxm-preview-trigger klxm-preview-link" data-preview-type="image" data-preview-url="' . htmlspecialchars($previewUrl) . '" data-preview-title="' . htmlspecialchars($caption) . '">' . $thumb . '</a>';
        }

        if ($extension === 'pdf') {
            $thumbPath = self::getPdfThumbnailPath($filename, 'small');
            if ($thumbPath !== null && is_file($thumbPath)) {
                $thumbPreviewUrl = self::buildShareUrl($share, $token, [
                    'klxm_board_share_download' => 'preview_thumb',
                    'file' => $filename,
                    'variant' => 'small',
                ]);
                $largePreviewUrl = self::buildShareUrl($share, $token, [
                    'klxm_board_share_download' => 'preview_thumb',
                    'file' => $filename,
                    'variant' => 'large',
                ]);
                $thumb = '<img class="klxm-preview-thumb" src="' . htmlspecialchars($thumbPreviewUrl) . '" alt="' . htmlspecialchars($displayName) . '" loading="lazy" decoding="async">';
                return '<a href="#" class="klxm-preview-trigger klxm-preview-link" data-preview-type="image" data-preview-url="' . htmlspecialchars($largePreviewUrl) . '" data-preview-title="' . htmlspecialchars($caption) . '">' . $thumb . '</a>';
            }

            return '<div class="klxm-filetype-tile">'
                . self::renderFileTypeIconSvg()
                . '<span class="klxm-filetype-label">PDF</span>'
                . '</div>';
        }

            if (self::isVideoExtension($extension)) {
                return '<a href="#" class="klxm-preview-trigger" data-preview-type="video" data-preview-url="' . htmlspecialchars($previewUrl) . '" data-preview-title="' . htmlspecialchars($caption) . '">'
                . '<div class="klxm-filetype-tile">'
                . self::renderFileTypeIconSvg()
                . '<span class="klxm-filetype-label">' . htmlspecialchars(strtoupper($extension)) . '</span>'
                . '</div></a>';
            }

        $label = strtoupper($extension !== '' ? $extension : 'FILE');

        return '<div class="klxm-filetype-tile">'
            . self::renderFileTypeIconSvg()
            . '<span class="klxm-filetype-label">' . htmlspecialchars($label) . '</span>'
            . '</div>';
    }

    private static function renderFileTypeIconSvg(): string
    {
        return '<svg class="klxm-filetype-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 2a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.2a2 2 0 0 0-.58-1.4L14.2 2.58A2 2 0 0 0 12.8 2H7Zm6 1.9 4.1 4.1H14a1 1 0 0 1-1-1V3.9ZM9 13a1 1 0 1 0 0 2h6a1 1 0 1 0 0-2H9Zm0 4a1 1 0 1 0 0 2h4a1 1 0 1 0 0-2H9Z"/></svg>';
    }

    private static function isImageExtension(string $extension): bool
    {
        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'], true);
    }

    private static function isVideoExtension(string $extension): bool
    {
        return in_array($extension, ['mp4', 'mov', 'webm', 'm4v', 'avi', 'mkv'], true);
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function sendInlinePreview(array $share, string $filename): never
    {
        $path = self::resolveSharedMediaPath($share, $filename);

        $mimeType = (string) (rex_file::mimeType($path) ?: 'application/octet-stream');
        rex_response::sendFile($path, $mimeType, 'inline', $filename);
        exit;
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function sendPdfThumbnailPreview(array $share, string $filename, string $variant): never
    {
        $path = self::resolveSharedMediaPath($share, $filename);
        if (strtolower(rex_file::extension($filename)) !== 'pdf') {
            self::sendText('Vorschau für diesen Dateityp nicht verfügbar.', rex_response::HTTP_BAD_REQUEST);
        }

        if ($variant !== 'large') {
            $variant = 'small';
        }

        $thumbPath = self::getPdfThumbnailPath($filename, $variant);
        if ($thumbPath === null || !is_file($thumbPath)) {
            self::sendText('PDF-Vorschau nicht verfügbar.', rex_response::HTTP_NOT_FOUND);
        }

        $mimeType = (string) (rex_file::mimeType($thumbPath) ?: 'image/jpeg');
        rex_response::sendFile($thumbPath, $mimeType, 'inline', basename($thumbPath));
        exit;
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function resolveSharedMediaPath(array $share, string $filename): string
    {
        if ($filename === '' || basename($filename) !== $filename || str_contains($filename, '/') || str_contains($filename, '\\')) {
            self::sendText('Ungültiger Dateiname.', rex_response::HTTP_BAD_REQUEST);
        }

        $allowed = self::collectAllowedFilenames($share);
        if (!in_array($filename, $allowed, true)) {
            self::sendText('Datei ist nicht Teil der Freigabe.', rex_response::HTTP_FORBIDDEN);
        }

        $path = rex_path::media($filename);
        if (!is_file($path)) {
            self::sendText('Datei nicht gefunden.', rex_response::HTTP_NOT_FOUND);
        }

        return $path;
    }

    private static function resolveUikitIconForExtension(string $extension): string
    {
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'], true)) {
            return 'image';
        }
        if (in_array($extension, ['mp4', 'mov', 'webm', 'm4v', 'avi', 'mkv'], true)) {
            return 'video-camera';
        }
        if (in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'flac'], true)) {
            return 'album';
        }
        if (in_array($extension, ['zip', 'gz', 'rar', '7z', 'tar'], true)) {
            return 'folder';
        }
        if (in_array($extension, ['doc', 'docx', 'odt', 'rtf', 'txt', 'md', 'pdf'], true)) {
            return 'file-text';
        }
        if (in_array($extension, ['xls', 'xlsx', 'csv', 'ods'], true)) {
            return 'table';
        }
        if (in_array($extension, ['json', 'xml', 'yml', 'yaml', 'js', 'ts', 'css', 'scss', 'php', 'html'], true)) {
            return 'code';
        }

        return 'file-edit';
    }

    private static function getPdfThumbnailPath(string $filename, string $variant = 'small'): ?string
    {
        if ($variant !== 'large') {
            $variant = 'small';
        }

        $cacheKey = $variant . '::' . $filename;

        if (array_key_exists($cacheKey, self::$pdfThumbnailPathCache)) {
            return self::$pdfThumbnailPathCache[$cacheKey];
        }

        self::$pdfThumbnailPathCache[$cacheKey] = null;

        $pdfout = rex_addon::get('pdfout');
        if (!$pdfout->isAvailable() || !class_exists(\FriendsOfRedaxo\PdfOut\PdfThumbnail::class)) {
            return null;
        }

        $pdfPath = rex_path::media($filename);
        if (!is_file($pdfPath)) {
            return null;
        }

        try {
            $thumbnail = new \FriendsOfRedaxo\PdfOut\PdfThumbnail();
            $maxWidth = $variant === 'large' ? 1600 : 320;
            $quality = $variant === 'large' ? 84 : 72;
            $thumbnail
                ->setFormat('jpg')
                ->setDpi(120)
                ->setQuality($quality)
                ->setPage(1)
                ->setMaxWidth($maxWidth);

            $thumbnailPath = $thumbnail->generate($pdfPath);
            if (!is_string($thumbnailPath) || $thumbnailPath === '' || !is_file($thumbnailPath)) {
                return null;
            }

            self::$pdfThumbnailPathCache[$cacheKey] = $thumbnailPath;

            return $thumbnailPath;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function buildZipDownloadFilename(array $share): string
    {
        $rawTitle = trim((string) ($share['title'] ?? ''));
        $fallback = 'dateifreigabe-' . (int) ($share['id'] ?? 0);

        if ($rawTitle === '') {
            return $fallback . '.zip';
        }

        $normalized = strtolower(trim(rex_string::normalize($rawTitle, '-'), '-_'));
        if ($normalized === '') {
            $normalized = $fallback;
        }

        return $normalized . '.zip';
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = max(0, min($power, count($units) - 1));
        $value = $bytes / (1024 ** $power);

        return number_format($value, $power === 0 ? 0 : 1, ',', '.') . ' ' . $units[$power];
    }

    private static function formatDate(string $date): string
    {
        if ($date === '' || $date === '0000-00-00 00:00:00') {
            return '-';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        return date('d.m.Y H:i', $timestamp);
    }

    private static function sendText(string $text, string $statusCode): never
    {
        rex_response::cleanOutputBuffers();
        rex_response::setStatus($statusCode);
        rex_response::sendCacheControl('no-store, no-cache, must-revalidate');
        rex_response::sendContent('<!doctype html><meta charset="utf-8"><p>' . htmlspecialchars($text) . '</p>', 'text/html; charset=utf-8');
        exit;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function sendJson(array $data, string $statusCode = rex_response::HTTP_OK): never
    {
        rex_response::cleanOutputBuffers();
        rex_response::setStatus($statusCode);
        rex_response::setHeader('Content-Type', 'application/json; charset=utf-8');
        rex_response::sendCacheControl('no-store, no-cache, must-revalidate');
        rex_response::sendContent((string) json_encode($data, JSON_UNESCAPED_UNICODE));
        exit;
    }
}
