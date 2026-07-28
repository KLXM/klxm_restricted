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
        array $fileDownloadLimits,
        string $createdBy
    ): string {
        $token = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_file_share'));
        $sql->setValue('token_hash', $tokenHash);
        $sql->setValue('token_plain', $token);
        $sql->setValue('token_hint', substr($token, 0, 12));
        $sql->setValue('share_mode', 'article');
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
        $sql->setValue('file_download_limits_json', self::encodeFileDownloadLimits($fileDownloadLimits));
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
        ?int $maxDownloads,
        array $fileDownloadLimits
    ): void {
        $existing = self::getShareById($shareId);
        if ($existing === null) {
            return;
        }

        $sql = rex_sql::factory();
        $sql->setTable(rex::getTable('klxm_restricted_file_share'));
        $sql->setWhere('id = :id', ['id' => $shareId]);
        $sql->setValue('share_mode', 'article');
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
        $sql->setValue('file_download_limits_json', self::encodeFileDownloadLimits($fileDownloadLimits));

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
            self::downloadSingleFile($share, $filename, $token, $requestId);
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
                $allowed = self::collectDownloadableFilenames($share);
                $selected = array_values(array_filter(array_values(array_unique($selected)), static fn (string $filename): bool => in_array($filename, $allowed, true)));
            } else {
                $selected = self::collectDownloadableFilenames($share);
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
            self::downloadZip($share, self::collectDownloadableFilenames($share), 'zip_all', $requestId);
        }

        if ($downloadMode === 'zip_selected') {
            $selectedRaw = rex_request::post('selected_files', 'array', []);
            $selected = [];
            foreach ($selectedRaw as $item) {
                if (is_string($item) && $item !== '') {
                    $selected[] = $item;
                }
            }

            $allowed = self::collectDownloadableFilenames($share);
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
            return $automaticShareWarning
                . self::consumeShareUiMessageHtml((int) ($share['id'] ?? 0))
                . self::renderShareList($share, $token, $filesByGroup);
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
            if ($token !== '') {
                return self::renderLockedMessage($token);
            }

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

        return self::consumeShareUiMessageHtml((int) ($shareFromToken['id'] ?? 0))
            . self::renderShareList($shareFromToken, $token, $filesByGroup);
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
        return '<style>.klxm-card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:20px}.klxm-file-card{border:1px solid var(--klxm-line);border-radius:16px;background:linear-gradient(180deg,#fff 0%,#fafcff 100%);padding:22px;display:flex;flex-direction:column;gap:16px;min-height:100%;width:100%;box-shadow:0 10px 28px rgba(15,23,36,.06)}.klxm-file-card .klxm-file-preview .klxm-preview-link,.klxm-file-card .klxm-file-preview .klxm-filetype-tile{width:168px;height:112px}.klxm-file-card-title{font-weight:700;font-size:1.08rem;line-height:1.35}.klxm-file-card-name{display:block;margin-top:4px;font-size:1rem}.klxm-file-card-meta{display:flex;flex-wrap:wrap;gap:10px;font-size:.95rem}.klxm-file-card-more{margin-top:-2px}.klxm-file-card-more .uk-button-text{padding:0;min-height:auto;font-weight:700}.klxm-file-card-actions{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}.klxm-display-wrap{display:flex;align-items:center;gap:8px;flex:0 1 250px;min-width:220px;max-width:100%}.klxm-display-wrap .uk-select{width:100%}.klxm-preview-link{display:inline-flex;width:84px;height:56px;align-items:center;justify-content:center;border:1px solid #e5e5e5;border-radius:4px;background:#fff;overflow:hidden}.klxm-filetype-tile{width:84px;height:56px;border:1px solid #e5e5e5;border-radius:4px;background:#f8f8f8;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px}.klxm-filetype-icon{width:18px;height:18px;display:block;fill:#5f7286}.klxm-filetype-label{font-size:10px;line-height:1;color:#5f7286;font-weight:700;letter-spacing:.03em}.klxm-toolbar-controls{flex:0 1 360px;min-width:240px}.klxm-toolbar-menu{border:1px solid var(--klxm-line);border-radius:12px;background:#fff;padding:0 12px;box-shadow:0 8px 18px rgba(15,23,36,.04)}.klxm-toolbar-menu>summary{list-style:none;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;font-weight:700}.klxm-toolbar-menu>summary::-webkit-details-marker{display:none}.klxm-toolbar-menu-panel{display:grid;gap:10px;padding:0 0 12px}.klxm-toolbar-menu-row{display:grid;gap:6px}.klxm-toolbar-menu-label{font-size:.72rem;line-height:1.2;text-transform:uppercase;letter-spacing:.03em;color:var(--klxm-muted);font-weight:700}.klxm-toolbar-menu .uk-select{width:100%}.klxm-card-grid--compact{grid-template-columns:repeat(auto-fit,minmax(280px,1fr))}.klxm-card-grid--detail{grid-template-columns:repeat(auto-fit,minmax(340px,1fr))}.klxm-card-grid--tiles{grid-template-columns:repeat(auto-fit,minmax(420px,1fr))}.klxm-file-card--compact{gap:12px}.klxm-file-card--compact .klxm-file-preview .klxm-preview-link,.klxm-file-card--compact .klxm-file-preview .klxm-filetype-tile{width:128px;height:86px}.klxm-file-card--detail .klxm-file-preview .klxm-preview-link,.klxm-file-card--detail .klxm-file-preview .klxm-filetype-tile{width:156px;height:104px}.klxm-file-card--tiles{gap:18px}.klxm-file-card--tiles .klxm-file-preview .klxm-preview-link,.klxm-file-card--tiles .klxm-file-preview .klxm-filetype-tile{width:184px;height:122px}.klxm-preview-overlay{display:none;position:fixed;inset:0;z-index:11000;background:rgba(7,16,28,.86);padding:20px}.klxm-preview-overlay.is-open{display:flex;align-items:center;justify-content:center}.klxm-preview-dialog{position:relative;width:min(1120px,calc(100vw - 40px));max-height:calc(100vh - 40px);background:#fff;border-radius:10px;box-shadow:0 24px 56px rgba(0,0,0,.35);overflow:hidden}.klxm-preview-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 14px;border-bottom:1px solid var(--klxm-line)}.klxm-preview-title{font-size:.95rem;font-weight:700;color:var(--klxm-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.klxm-preview-close{border:1px solid var(--klxm-line);background:#fff;border-radius:8px;padding:6px 10px;cursor:pointer;font-weight:700}.klxm-preview-body{background:#0f1724;min-height:280px;max-height:calc(100vh - 110px);display:flex;align-items:center;justify-content:center}.klxm-preview-body img{max-width:100%;max-height:calc(100vh - 140px);display:block}.klxm-preview-body iframe{width:min(1040px,96vw);height:min(78vh,980px);border:0;background:#fff}.klxm-preview-body video{width:min(1040px,96vw);max-height:calc(100vh - 140px);background:#000;display:block}@media (max-width:980px){.klxm-sort-wrap,.klxm-jump-wrap{min-width:180px}.klxm-toolbar-controls{flex:1 1 100%;min-width:0}.klxm-toolbar-menu{width:100%}.klxm-card-grid--compact,.klxm-card-grid--detail,.klxm-card-grid--tiles{grid-template-columns:1fr}}@media (max-width:860px){.uk-card-body{padding:12px}.uk-button,.uk-input,.uk-select,.uk-textarea{font-size:14px}.uk-table th,.uk-table td{font-size:.86rem}.klxm-brand{align-items:flex-start;flex-direction:column}.klxm-toolbar-main{align-items:stretch}.klxm-toolbar-zip,.klxm-toolbar-status,.klxm-search-wrap,.klxm-sort-wrap,.klxm-jump-wrap{flex:1 1 100%;min-width:0;width:100%}.uk-button-group{display:flex;flex-wrap:wrap}.uk-button-group .uk-button{flex:1 1 auto}.klxm-preview-overlay{padding:10px}.klxm-preview-dialog{width:calc(100vw - 20px);max-height:calc(100vh - 20px)}.klxm-preview-body iframe{width:100%;height:74vh}}@media (max-width:760px){.klxm-group-block .uk-overflow-auto{overflow:visible}.klxm-mobile-group-actions{display:flex}.klxm-files-table,.klxm-files-table thead,.klxm-files-table tbody,.klxm-files-table tr,.klxm-files-table th,.klxm-files-table td{display:block;width:100%}.klxm-files-table thead{display:none}.klxm-files-table tr{margin:0 0 12px;padding:10px 12px;border:1px solid var(--klxm-line);border-radius:10px;background:#fff}.klxm-files-table td{border-bottom:0;padding:6px 0}.klxm-files-table td[data-label]::before{content:attr(data-label);display:block;margin-bottom:3px;font-size:.72rem;line-height:1.2;text-transform:uppercase;letter-spacing:.03em;color:var(--klxm-muted);font-weight:700}.klxm-files-table td:first-child{padding-top:0}.klxm-files-table td.uk-text-nowrap{text-align:left;white-space:normal}.klxm-files-table td:last-child{padding-bottom:0}.klxm-files-table .uk-icon-button{width:100%}.klxm-files-table .uk-text-right{text-align:left}.klxm-files-table .klxm-file-checkbox{transform:scale(1.05)}.klxm-file-main{gap:8px}.klxm-file-main .klxm-preview-link,.klxm-file-main .klxm-filetype-tile{width:54px;height:38px}.klxm-file-main .klxm-filetype-icon{width:14px;height:14px}.klxm-file-main .klxm-filetype-label{font-size:8px}.klxm-file-card .klxm-file-preview .klxm-preview-link,.klxm-file-card .klxm-file-preview .klxm-filetype-tile{width:112px;height:76px}.klxm-card-details-dialog{width:100vw}.klxm-card-details-body{padding:12px}.klxm-display-wrap{flex:1 1 100%;min-width:0;width:100%}.klxm-card-grid{grid-template-columns:1fr}.klxm-file-card{padding:16px}}</style>';
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
        $displayMode = strtolower(trim(rex_request::get('klxm_display', 'string', 'list')));
        if (in_array($displayMode, ['compact', 'detail'], true)) {
            $displayMode = 'list';
        }
        if (!in_array($displayMode, ['list', 'tiles'], true)) {
            $displayMode = 'list';
        }
        $filesByGroup = self::sortFilesByCategoryAndTitle($filesByGroup, $sortDirection);
        $limitReachedDisplay = (string) rex_addon::get('klxm_restricted')->getConfig('share_limit_reached_display', 'disabled');
        if (!in_array($limitReachedDisplay, ['hide', 'disabled'], true)) {
            $limitReachedDisplay = 'disabled';
        }
        $perFileLimits = self::decodeFileDownloadLimits($share);
        $perFileCounts = self::getPerFileDownloadCountMap((int) ($share['id'] ?? 0));

        if ($limitReachedDisplay === 'hide') {
            $filteredGroups = [];
            foreach ($filesByGroup as $group) {
                if (!isset($group['files']) || !is_array($group['files'])) {
                    continue;
                }

                $filteredFiles = [];
                foreach ($group['files'] as $file) {
                    if (!is_array($file)) {
                        continue;
                    }

                    $filename = (string) ($file['filename'] ?? '');
                    if ($filename === '') {
                        continue;
                    }

                    $fileLimitMax = (int) ($perFileLimits[$filename] ?? 0);
                    $fileLimitCurrent = (int) ($perFileCounts[$filename] ?? 0);
                    if ($fileLimitMax > 0 && $fileLimitCurrent >= $fileLimitMax) {
                        continue;
                    }

                    $filteredFiles[] = $file;
                }

                if ($filteredFiles !== []) {
                    $group['files'] = $filteredFiles;
                    $filteredGroups[] = $group;
                }
            }
            $filesByGroup = $filteredGroups;
        }

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
        $jumpDropdownId = 'klxm-jump-dropdown-' . (int) $share['id'];
        $statusCreateUrl = self::buildCurrentShareUrl($token, ['klxm_board_share_download' => 'zip_async_create']);
        $shareBaseUrl = self::buildShareUrl($share, $token, []);
        $hasJumpMenu = count($filesByGroup) > 2;
        $sortLabel = 'Standard';
        if ($sortDirection === 'asc') {
            $sortLabel = 'A-Z';
        } elseif ($sortDirection === 'desc') {
            $sortLabel = 'Z-A';
        }
        $sortAriaLabel = 'Sortierung: ' . $sortLabel;

        $html = self::renderShareBaseStyles($branding['accent']);
        $html .= '<style>'
            . '.klxm-file-card{overflow:hidden}'
            . '.klxm-file-preview{width:100%;aspect-ratio:16/9;background:#f3f6fb;display:flex;align-items:center;justify-content:center;overflow:hidden}'
            . '.klxm-file-preview .klxm-preview-link,.klxm-file-preview .klxm-filetype-tile{width:100%!important;height:100%!important;max-width:none!important;max-height:none!important;border:0;border-radius:0}'
            . '.klxm-file-preview .klxm-preview-thumb{width:100%;height:100%;object-fit:contain;display:block}'
            . '.klxm-file-card-title{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;text-overflow:ellipsis;min-height:2.7em;overflow-wrap:anywhere;word-break:break-word}'
            . '.klxm-file-card-name{display:flex;align-items:center;gap:6px;max-width:100%}'
            . '.klxm-file-card-name-text{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;flex:1 1 auto}'
            . '.klxm-file-card-name .klxm-info-indicator{display:inline-flex;align-items:center;justify-content:center;margin-left:6px;color:#0f5fa8;vertical-align:middle}'
            . '.klxm-file-card-name .klxm-info-indicator svg{width:16px;height:16px}'
            . '.klxm-file-card-name .klxm-info-indicator svg path{fill:currentColor!important}'
            . '.klxm-file-card-name .klxm-info-indicator svg circle{stroke:currentColor!important}'
            . '.klxm-file-card-quota{display:block;margin-top:4px;font-size:.86rem;color:#4f5f73}'
            . '.klxm-file-card-quota--reached{color:#b42318;font-weight:700}'
            . '.klxm-file-card .uk-card-body{display:flex;flex-direction:column;gap:10px}'
            . '.klxm-file-card-actions{margin-top:auto;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:nowrap}'
            . '.klxm-file-card-actions .uk-form-label{display:inline-flex;align-items:center;gap:8px;margin:0;flex:1 1 auto;min-width:0}'
            . '.klxm-file-card-actions .uk-form-label input{margin:0}'
            . '.klxm-file-card-action-links{display:inline-flex;align-items:center;gap:10px;flex:0 0 auto}'
            . '.klxm-file-card-actions .klxm-details-trigger{white-space:nowrap;padding:0;min-height:auto}'
            . '.klxm-list-table-wrap{border:1px solid #d9e2ec;border-radius:12px;overflow:hidden;background:#fff}'
            . '.klxm-list-table-wrap .uk-overflow-auto{margin:0}'
            . '.klxm-file-table{margin:0;table-layout:fixed;width:100%}'
            . '.klxm-file-table th{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:#4f5f73;white-space:nowrap}'
            . '.klxm-file-table td{vertical-align:middle}'
            . '.klxm-file-table .klxm-col-select{width:84px}'
            . '.klxm-file-table .klxm-col-preview{width:84px}'
            . '.klxm-file-table .klxm-col-type{width:110px}'
            . '.klxm-file-table .klxm-col-size{width:110px}'
            . '.klxm-file-table .klxm-col-updated{width:150px}'
            . '.klxm-file-table .klxm-col-actions{width:170px}'
            . '.klxm-file-table .klxm-col-preview,.klxm-file-table td.klxm-col-preview{overflow:hidden}'
            . '.klxm-file-table .klxm-row-title{font-weight:700;line-height:1.3;overflow-wrap:anywhere;word-break:break-word}'
            . '.klxm-file-table .klxm-row-name{display:flex;align-items:center;gap:6px;color:#4f5f73;max-width:100%}'
            . '.klxm-file-table .klxm-row-name-text{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;flex:1 1 auto}'
            . '.klxm-file-table .klxm-row-name .klxm-info-indicator{display:inline-flex;align-items:center;justify-content:center;margin-left:6px;color:#0f5fa8;vertical-align:middle}'
            . '.klxm-file-table .klxm-row-name .klxm-info-indicator svg{width:16px;height:16px}'
            . '.klxm-file-table .klxm-row-name .klxm-info-indicator svg path{fill:currentColor!important}'
            . '.klxm-file-table .klxm-row-name .klxm-info-indicator svg circle{stroke:currentColor!important}'
            . '.klxm-file-table .klxm-row-quota{display:block;margin-top:4px;font-size:.82rem;color:#4f5f73}'
            . '.klxm-file-table .klxm-row-quota--reached{color:#b42318;font-weight:700}'
            . '.klxm-file-table .klxm-row-preview .klxm-preview-link{display:inline-flex;align-items:center;justify-content:center;width:78px;height:auto;max-height:56px;border:0;background:transparent;padding:0}'
            . '.klxm-file-table .klxm-row-preview .klxm-preview-thumb{display:block;width:78px!important;height:auto!important;max-height:56px;object-fit:contain}'
            . '.klxm-file-table .klxm-row-preview .klxm-filetype-tile{width:72px;height:48px}'
            . '.klxm-file-table .klxm-row-actions{display:inline-flex;align-items:center;gap:10px;justify-content:center;width:100%}'
            . '.klxm-file-table .klxm-row-actions .uk-icon-button{flex:0 0 auto}'
            . '.klxm-tooltip-wrap{display:inline-flex;align-items:center}'
            . '.klxm-row-actions .uk-icon-button,.klxm-file-card-action-links .uk-icon-button{appearance:none;-webkit-appearance:none;border:1px solid #c7d4e3;background:#fff;color:#155fa0;box-shadow:none;outline:none}'
            . '.klxm-row-actions .uk-icon-button:hover,.klxm-file-card-action-links .uk-icon-button:hover{background:#f3f8ff;color:#0f4f8a;border-color:#a9bfd8}'
            . '.klxm-row-actions .uk-icon-button[disabled],.klxm-file-card-action-links .uk-icon-button[disabled]{background:#f7f9fc;color:#9aa8ba;border-color:#d7e0ea;cursor:not-allowed}'
            . '.klxm-row-actions .uk-icon-button .klxm-download-icon svg line,.klxm-row-actions .uk-icon-button .klxm-download-icon svg polyline,.klxm-row-actions .uk-icon-button .klxm-download-icon svg path,.klxm-row-actions .uk-icon-button .klxm-download-icon svg circle,.klxm-file-card-action-links .uk-icon-button .klxm-download-icon svg line,.klxm-file-card-action-links .uk-icon-button .klxm-download-icon svg polyline,.klxm-file-card-action-links .uk-icon-button .klxm-download-icon svg path,.klxm-file-card-action-links .uk-icon-button .klxm-download-icon svg circle{stroke:currentColor!important;fill:none!important}'
            . '.klxm-file-table .klxm-row-actions .klxm-details-trigger{white-space:nowrap;padding:0;min-height:auto}'
            . '.klxm-file-table .klxm-title-trigger{padding:0;min-height:auto;display:block;text-align:left;width:100%}'
            . '.klxm-file-table .klxm-title-trigger.is-clickable{cursor:pointer}'
            . '.klxm-file-table .klxm-title-trigger.is-clickable:hover .klxm-row-title{text-decoration:underline}'
            . '.klxm-file-table .klxm-row-select{display:inline-flex;align-items:center;justify-content:center;gap:8px;margin:0;min-height:28px}'
            . '.klxm-toolbar-status{flex:0 0 120px;min-width:100px}'
            . '.klxm-search-wrap{flex:1 1 560px;min-width:360px}'
            . '.klxm-toolbar-controls{flex:0 0 auto;margin-left:auto}'
            . '.klxm-toolbar-quick{display:inline-flex;align-items:center;gap:8px;flex-wrap:nowrap}'
            . '.klxm-toolbar-quick .uk-button{min-height:34px;padding:0 12px}'
            . '.klxm-btn-icon{display:inline-flex;align-items:center;gap:6px}'
            . '.klxm-btn-icon svg{width:14px;height:14px;display:block}'
            . '.klxm-mobile-group-actions{display:flex;align-items:center;gap:12px;flex-wrap:wrap}'
            . '.klxm-mobile-group-actions label{display:inline-flex;align-items:center;gap:8px;margin:0}'
            . '.klxm-mobile-group-note{display:inline-block;margin-left:4px}'
            . '.klxm-jump-dropdown{min-width:230px}'
            . '.klxm-jump-link{display:block;width:100%;text-align:left;padding:6px 0;border:0;background:transparent;cursor:pointer}'
            . '.klxm-file-details-modal{display:grid;gap:8px}'
            . '.klxm-file-details-row{display:grid;grid-template-columns:minmax(120px,160px) 1fr;gap:10px;align-items:start}'
            . '.klxm-file-details-label{font-weight:700;color:#4f5f73}'
            . '[id^="klxm-file-details-"] .uk-modal-dialog{width:min(1120px,calc(100vw - 40px));max-width:1120px;overflow-x:hidden}'
            . '[id^="klxm-file-details-"] .uk-modal-title{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden;text-overflow:ellipsis;line-height:1.2;white-space:normal!important;max-width:100%;padding-right:56px;overflow-wrap:anywhere;word-break:break-word}'
            . '[id^="klxm-file-details-"] .klxm-file-details-value{min-width:0;overflow-wrap:anywhere;word-break:break-word}'
            . '@media (max-width:1040px){.klxm-file-table .klxm-col-updated{display:none}.klxm-file-table td.klxm-col-updated{display:none}}'
            . '@media (max-width:860px){.klxm-file-table .klxm-col-type,.klxm-file-table .klxm-col-size{display:none}.klxm-file-table td.klxm-col-type,.klxm-file-table td.klxm-col-size{display:none}}'
            . '@media (max-width:920px){.klxm-toolbar-status{flex:1 1 100%;min-width:0}.klxm-search-wrap{flex:1 1 100%;min-width:0}.klxm-toolbar-controls{flex:1 1 100%;margin-left:0}.klxm-toolbar-quick{width:100%}}'
            . '@media (max-width:700px){.klxm-list-table-wrap{border:0;background:transparent}.klxm-file-table thead{display:none}.klxm-file-table,.klxm-file-table tbody,.klxm-file-table tr,.klxm-file-table td{display:block;width:100%}.klxm-file-table tr{background:#fff;border:1px solid #d9e2ec;border-radius:12px;padding:10px;margin:0 0 10px}.klxm-file-table td{border:0!important;padding:6px 0}.klxm-file-table td[data-label]::before{content:attr(data-label);display:block;font-size:.72rem;font-weight:700;letter-spacing:.03em;color:#4f5f73;text-transform:uppercase;margin-bottom:4px}.klxm-file-table .klxm-row-preview .klxm-preview-link{width:auto;max-width:100%;max-height:104px}.klxm-file-table .klxm-row-preview .klxm-preview-thumb{width:auto!important;max-width:100%;max-height:104px}.klxm-file-table .klxm-row-preview .klxm-filetype-tile{width:88px;height:60px}.klxm-file-table .klxm-col-actions .klxm-row-actions{justify-content:flex-start}.klxm-file-table .klxm-col-select .klxm-row-select{justify-content:flex-start}.klxm-mobile-group-actions{gap:8px}.klxm-mobile-group-note{margin-left:0}.klxm-toolbar-quick{flex-wrap:wrap}.klxm-file-card-actions{flex-wrap:wrap;row-gap:8px}.klxm-file-card-action-links{margin-left:auto}.klxm-file-details-row{grid-template-columns:1fr}}'
            . '</style>';
        $html .= '<section class="uk-section uk-section-small" data-klxm-share-id="' . (int) $share['id'] . '" uk-lightbox="animation: slide">';
        $html .= '<div class="uk-container">';
        $html .= '<div class="uk-card uk-card-default uk-card-body">';
        $html .= self::renderShareBrandingHeader($branding);
        $html .= '<h2 class="uk-card-title">' . htmlspecialchars($headline) . '</h2>';
        if ($description !== '') {
            $html .= '<p class="uk-text-meta">' . nl2br(htmlspecialchars($description)) . '</p>';
        }
        $html .= '</div>';

        if ($filesByGroup === []) {
            $html .= '<div class="uk-card uk-card-default uk-card-body uk-margin-top"><div class="uk-alert-primary" uk-alert>Aktuell sind keine Dateien verfügbar.</div></div>';
            $html .= '</div></section>';
            return $html;
        }

        $html .= '<div class="uk-card uk-card-default uk-card-body uk-margin-top uk-margin-medium-bottom klxm-share-toolbar" style="position:sticky;top:' . $stickyOffset . 'px;z-index:95;" aria-label="Werkzeugleiste der Dateifreigabe">';
        $html .= '<div class="uk-flex uk-flex-wrap uk-flex-middle uk-grid-small klxm-toolbar-main" uk-grid>';

        if ($allowZip) {
            $html .= '<div class="uk-width-auto@s klxm-toolbar-zip">';
            $html .= '<div class="uk-button-group">';
            $html .= '<button type="button" class="uk-button uk-button-default klxm-zip-all-btn" aria-label="Alle Dateien als ZIP herunterladen">Alle als ZIP</button>';
            $html .= '<button type="button" class="uk-button uk-button-secondary klxm-zip-selected-btn" disabled aria-label="Ausgewählte Dateien als ZIP herunterladen">Ausgewählte als ZIP</button>';
            $html .= '</div></div>';
            $html .= '<div class="uk-width-expand@s klxm-toolbar-status"><span class="uk-text-meta klxm-zip-status"></span></div>';
        }

        $html .= '<div class="uk-width-expand@s klxm-search-wrap">';
        $html .= '<div class="uk-inline uk-width-1-1">';
        $html .= '<span class="uk-form-icon" uk-icon="icon: search"></span>';
        $html .= '<input class="uk-input klxm-live-search" type="text" placeholder="Dateien filtern" aria-label="Dateien filtern nach Name, Dateiname oder Beschreibung">';
        $html .= '</div></div>';

        $html .= '<div class="uk-width-auto@s klxm-toolbar-controls">';
        $html .= '<div class="klxm-toolbar-quick">';
        $html .= '<button type="button" class="uk-button uk-button-default uk-button-small klxm-view-toggle" aria-label="Ansicht umschalten"><span class="klxm-btn-icon">' . self::renderToolbarIconSvg($displayMode === 'tiles' ? 'list' : 'tiles') . '<span>' . ($displayMode === 'tiles' ? 'Liste' : 'Kacheln') . '</span></span></button>';
        $html .= '<button type="button" class="uk-button uk-button-default uk-button-small klxm-sort-toggle" aria-label="Sortierung umschalten"><span class="klxm-btn-icon">' . self::renderToolbarIconSvg('sort') . '<span>' . htmlspecialchars($sortLabel) . '</span></span></button>';
        if ($hasJumpMenu) {
            $html .= '<div class="uk-inline">';
            $html .= '<button type="button" class="uk-button uk-button-default uk-button-small klxm-jump-toggle" aria-label="Sprungmenü öffnen"><span class="klxm-btn-icon">' . self::renderToolbarIconSvg('jump') . '<span>Liste</span></span></button>';
            $html .= '<div id="' . htmlspecialchars($jumpDropdownId) . '" class="uk-card uk-card-default uk-card-body uk-box-shadow-small klxm-jump-dropdown" uk-dropdown="mode: click; pos: bottom-right; offset: 8">';
            $html .= '<ul class="uk-nav uk-nav-default">';
            foreach ($filesByGroup as $groupIndex => $group) {
                $anchorId = 'klxm-group-anchor-' . (int) $share['id'] . '-' . $groupIndex;
                $html .= '<li><button type="button" class="klxm-jump-link" data-target="#' . htmlspecialchars($anchorId) . '">' . htmlspecialchars($group['name']) . '</button></li>';
            }
            $html .= '</ul></div></div>';
        }
        $html .= '</div>';
        $html .= '</div>';

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

        foreach ($filesByGroup as $groupIndex => $group) {
            $groupId = 'klxm-file-group-' . (int) $share['id'] . '-' . $groupIndex;
            $groupAnchorId = 'klxm-group-anchor-' . (int) $share['id'] . '-' . $groupIndex;
            $downloadCsrf = rex_csrf_token::factory('klxm_restricted_file_share_download')->getValue();
            $singleDownloadedFiles = self::getSingleDownloadedFiles((int) ($share['id'] ?? 0));
            $html .= '<div class="uk-margin-medium klxm-group-block" id="' . htmlspecialchars($groupAnchorId) . '">';
            $html .= '<h3 class="uk-heading-bullet"><span>' . htmlspecialchars($group['name']) . '</span></h3>';
            $html .= '<form method="post" class="klxm-zip-selected-form" data-share-token="' . htmlspecialchars($token) . '">';
            $html .= '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($downloadCsrf) . '">';
            $html .= '<div class="klxm-mobile-group-actions"><label><input class="uk-checkbox klxm-select-group" type="checkbox" data-target="' . htmlspecialchars($groupId) . '" aria-label="Alle Dateien aus dieser Kategorie auswählen"> <span>Alle aus der Kategorie wählen</span></label><span class="klxm-mobile-group-note">Schnellauswahl für diese Gruppe</span></div>';
            if ($displayMode === 'list') {
                $html .= '<div id="' . htmlspecialchars($groupId) . '" class="klxm-list-table-wrap">';
                $html .= '<div class="uk-overflow-auto">';
                $html .= '<table class="uk-table uk-table-small uk-table-divider klxm-file-table">';
                $html .= '<thead><tr>';
                $html .= '<th class="klxm-col-select">Auswahl</th>';
                $html .= '<th class="klxm-col-preview">Vorschau</th>';
                $html .= '<th>Titel / Datei</th>';
                $html .= '<th class="klxm-col-type">Typ</th>';
                $html .= '<th class="klxm-col-size">Größe</th>';
                $html .= '<th class="klxm-col-updated">Aktualisiert</th>';
                $html .= '<th class="klxm-col-actions">Aktionen</th>';
                $html .= '</tr></thead><tbody>';

                foreach ($group['files'] as $fileIndex => $file) {
                    $displayName = trim($file['title']) !== '' ? $file['title'] : $file['filename'];
                    $filename = (string) ($file['filename'] ?? '');
                    $singleAlreadyDownloaded = isset($singleDownloadedFiles[$filename]);
                    $fileLimitMax = (int) ($perFileLimits[$filename] ?? 0);
                    $fileLimitCurrent = (int) ($perFileCounts[$filename] ?? 0);
                    $fileLimitReached = $fileLimitMax > 0 && $fileLimitCurrent >= $fileLimitMax;
                    $fileTypeLabel = self::formatCompactFileTypeLabel($filename);
                    $previewHtml = self::renderFilePreview($share, $token, $file, $displayName);
                    $descriptionHtml = self::renderDescriptionCell((string) $file['description'], (int) $share['id'], (int) $groupIndex, (int) $fileIndex);
                    $html .= self::renderListRow($share, $token, $file, $displayName, $previewHtml, $descriptionHtml, $fileTypeLabel, $singleAlreadyDownloaded, $fileLimitReached, $fileLimitCurrent, $fileLimitMax);
                }

                $html .= '</tbody></table>';
                $html .= '</div></div>';
            } else {
                $html .= '<div id="' . htmlspecialchars($groupId) . '" class="klxm-card-grid klxm-card-grid--' . htmlspecialchars($displayMode) . '">';
                foreach ($group['files'] as $fileIndex => $file) {
                    $displayName = trim($file['title']) !== '' ? $file['title'] : $file['filename'];
                    $filename = (string) ($file['filename'] ?? '');
                    $singleAlreadyDownloaded = isset($singleDownloadedFiles[$filename]);
                    $fileLimitMax = (int) ($perFileLimits[$filename] ?? 0);
                    $fileLimitCurrent = (int) ($perFileCounts[$filename] ?? 0);
                    $fileLimitReached = $fileLimitMax > 0 && $fileLimitCurrent >= $fileLimitMax;
                    $fileTypeLabel = self::formatCompactFileTypeLabel($filename);
                    $previewHtml = self::renderFilePreview($share, $token, $file, $displayName);
                    $descriptionHtml = self::renderDescriptionCell((string) $file['description'], (int) $share['id'], (int) $groupIndex, (int) $fileIndex);
                    $html .= self::renderTileCard($share, $token, $file, $displayName, $previewHtml, $descriptionHtml, $fileTypeLabel, $singleAlreadyDownloaded, $fileLimitReached, $fileLimitCurrent, $fileLimitMax, $displayMode);
                }
                $html .= '</div>';
            }
            $html .= '</form></div>';
        }

        $html .= '</div></section>';
        $html .= '<script>(function(){'
            . 'var root=document.querySelector("[data-klxm-share-id=\"' . (int) $share['id'] . '\"]");'
            . 'if(!root||root.getAttribute("data-klxm-share-init")==="1"){return;}'
            . 'root.setAttribute("data-klxm-share-init","1");'
            . 'var createUrl=' . json_encode($statusCreateUrl) . ';'
            . 'var shareBaseUrl=' . json_encode($shareBaseUrl) . ';'
            . 'var modal=document.getElementById(' . json_encode($zipModalId) . ');'
            . 'var lastPreviewTrigger=null;'
            . 'var previewModal=null;'
            . 'function decodeUrl(u){return String(u||"").replace(/&amp;/g,"&");}'
            . 'function showModal(){if(!modal){return;}if(window.UIkit&&typeof window.UIkit.modal==="function"){window.UIkit.modal(modal).show();return;}modal.style.display="block";}'
            . 'function hideModal(){if(!modal){return;}if(window.UIkit&&typeof window.UIkit.modal==="function"){window.UIkit.modal(modal).hide();return;}modal.style.display="none";}'
            . 'function closePreview(){if(!previewModal){return;}if(document.activeElement&&previewModal.contains(document.activeElement)&&typeof document.activeElement.blur==="function"){document.activeElement.blur();}previewModal.classList.remove("is-open");previewModal.setAttribute("aria-hidden","true");var body=previewModal.querySelector(".klxm-preview-body");if(body){body.innerHTML="";}if(lastPreviewTrigger&&typeof lastPreviewTrigger.focus==="function"){lastPreviewTrigger.focus();}lastPreviewTrigger=null;}'
            . 'function openPreview(url,title,type){if(!previewModal){return;}var head=previewModal.querySelector(".klxm-preview-title");var body=previewModal.querySelector(".klxm-preview-body");if(head){head.textContent=title||"Vorschau";}if(body){if(type==="pdf"){body.innerHTML="<iframe src=\""+url.replace(/\"/g,"&quot;")+"\" loading=\"lazy\" title=\"Dateivorschau\"></iframe>";}else if(type==="video"){body.innerHTML="<video controls preload=\"metadata\" playsinline src=\""+url.replace(/\"/g,"&quot;")+"\"></video>";}else{body.innerHTML="<img src=\""+url.replace(/\"/g,"&quot;")+"\" alt=\""+(title||"Vorschau").replace(/\"/g,"&quot;")+"\">";}}previewModal.classList.add("is-open");previewModal.setAttribute("aria-hidden","false");}'
            . 'function setModalState(msg,isError,isReady){if(!modal){return;}var msgNode=modal.querySelector(".klxm-zip-modal-message");var errNode=modal.querySelector(".klxm-zip-modal-error");var spinNode=modal.querySelector(".klxm-zip-modal-spinner");if(msgNode){msgNode.textContent=msg;}if(spinNode){spinNode.hidden=!!isError||!!isReady;}if(errNode){if(isError){errNode.hidden=false;errNode.textContent=msg;}else{errNode.hidden=true;errNode.textContent="";}}}'
            . 'function setStatus(msg){root.querySelectorAll(".klxm-zip-status").forEach(function(el){el.textContent=msg;});if(msg!==""){showModal();var low=msg.toLowerCase();var isReady=low.indexOf("bereit")!==-1;var isError=low.indexOf("fehler")!==-1||low.indexOf("konnte nicht")!==-1||low.indexOf("fehlgeschlagen")!==-1;setModalState(msg,isError,isReady);if(isReady){window.setTimeout(hideModal,2000);}}}'
            . 'function collectSelected(){var selected=[];root.querySelectorAll(".klxm-file-checkbox:checked").forEach(function(cb){selected.push(cb.value);});return selected;}'
            . 'function refreshSelectedButton(){var btn=root.querySelector(".klxm-zip-selected-btn");if(!btn){return;}btn.disabled=collectSelected().length===0;}'
            . 'function poll(job){var u=new URL(decodeUrl(createUrl),window.location.origin);u.searchParams.set("klxm_board_share_download","zip_async_status");u.searchParams.set("zip_job",job);fetch(u.toString(),{credentials:"same-origin"}).then(function(r){return r.json();}).then(function(data){if(!data||!data.ok){setStatus((data&&data.message)?data.message:"ZIP-Statusfehler");return;}if(data.status==="queued"||data.status==="processing"){setStatus("ZIP wird erstellt ...");window.setTimeout(function(){poll(job);},1200);return;}if(data.status==="ready"){setStatus("ZIP bereit, Download startet ...");window.location.href=decodeUrl(data.download_url||"");window.setTimeout(function(){setStatus("");},2800);return;}setStatus(data.message||"ZIP fehlgeschlagen");}).catch(function(){setStatus("ZIP-Statusfehler");});}'
            . 'function create(kind,selected){setModalState("ZIP wird vorbereitet ...",false,false);showModal();var formData=new FormData();formData.set("zip_kind",kind);selected.forEach(function(name){formData.append("selected_files[]",name);});fetch(decodeUrl(createUrl),{method:"POST",body:formData,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(data){if(!data||!data.ok){setStatus((data&&data.message)?data.message:"ZIP konnte nicht gestartet werden");return;}setStatus("ZIP wird erstellt ...");poll(data.job);}).catch(function(){setStatus("ZIP konnte nicht gestartet werden");});}'
            . 'function applySearch(needle){var query=(needle||"").toLowerCase().trim();root.querySelectorAll("[data-search]").forEach(function(item){var hay=(item.getAttribute("data-search")||"").toLowerCase();item.style.display=(query===""||hay.indexOf(query)!==-1)?"":"none";});root.querySelectorAll(".klxm-group-block").forEach(function(block){var visibleItems=block.querySelectorAll("[data-search]:not([style*=\"display: none\"])").length;block.style.display=visibleItems>0?"":"none";});}'
            . 'root.addEventListener("change",function(e){var t=e.target;if(!(t instanceof Element)){return;}if(t.classList.contains("klxm-select-group")){var id=t.getAttribute("data-target")||"";var wrap=document.getElementById(id);if(wrap){wrap.querySelectorAll(".klxm-file-checkbox").forEach(function(cb){cb.checked=t.checked;});}root.querySelectorAll(".klxm-select-group").forEach(function(cb){if(cb!==t&&cb.getAttribute("data-target")===id){cb.checked=t.checked;}});refreshSelectedButton();return;}if(t.classList.contains("klxm-file-checkbox")){refreshSelectedButton();return;}});'
            . 'root.addEventListener("input",function(e){var t=e.target;if(t.classList.contains("klxm-live-search")){applySearch(t.value||"");}});'
            . 'root.addEventListener("click",function(e){var singleBtn=e.target.closest(".klxm-single-download-btn");if(singleBtn){if(singleBtn.disabled){e.preventDefault();return;}window.setTimeout(function(){singleBtn.disabled=true;singleBtn.classList.add("is-disabled");var reason=singleBtn.getAttribute("data-disabled-reason")||"Datei wurde bereits als Einzeldownload geladen.";singleBtn.setAttribute("title",reason);singleBtn.setAttribute("uk-tooltip",reason);if(window.UIkit&&typeof window.UIkit.tooltip==="function"){window.UIkit.tooltip(singleBtn);}},0);return;}var preview=e.target.closest(".klxm-preview-trigger");if(preview){e.preventDefault();lastPreviewTrigger=preview;openPreview(decodeUrl(preview.getAttribute("data-preview-url")||""),preview.getAttribute("data-preview-title")||"",preview.getAttribute("data-preview-type")||"image");return;}var jumpLink=e.target.closest(".klxm-jump-link");if(jumpLink){e.preventDefault();var target=jumpLink.getAttribute("data-target")||"";if(target!==""){var el=document.querySelector(target);if(el){if(window.UIkit&&typeof window.UIkit.dropdown==="function"){var dropEl=jumpLink.closest("[uk-dropdown]");if(dropEl){window.UIkit.dropdown(dropEl).hide(false);}}el.scrollIntoView({behavior:"smooth",block:"start"});}}return;}var viewToggle=e.target.closest(".klxm-view-toggle");if(viewToggle){e.preventDefault();var vu=new URL(decodeUrl(shareBaseUrl),window.location.origin);var current=(vu.searchParams.get("klxm_display")||"tiles").toLowerCase();var next=current==="tiles"?"list":"tiles";vu.searchParams.set("klxm_display",next);window.location.href=vu.toString();return;}var sortToggle=e.target.closest(".klxm-sort-toggle");if(sortToggle){e.preventDefault();var su=new URL(decodeUrl(shareBaseUrl),window.location.origin);var currentSort=(su.searchParams.get("klxm_sort")||"manual").toLowerCase();var nextSort="manual";if(currentSort==="manual"){nextSort="asc";}else if(currentSort==="asc"){nextSort="desc";}if(nextSort==="manual"){su.searchParams.delete("klxm_sort");}else{su.searchParams.set("klxm_sort",nextSort);}window.location.href=su.toString();return;}var allBtn=e.target.closest(".klxm-zip-all-btn");if(allBtn){e.preventDefault();create("all",[]);return;}var selectedBtn=e.target.closest(".klxm-zip-selected-btn");if(selectedBtn){e.preventDefault();var selected=collectSelected();if(selected.length===0){setStatus("Bitte zuerst Dateien auswählen.");return;}create("selected",selected);}});'
            . 'if(previewModal){previewModal.addEventListener("click",function(e){if(e.target===previewModal||e.target.closest(".klxm-preview-close")){closePreview();}});document.addEventListener("keydown",function(e){if(e.key==="Escape"&&previewModal.classList.contains("is-open")){closePreview();}});}'
            . 'refreshSelectedButton();'
            . '})();</script>';

        return $html;
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function renderListRow(
        array $share,
        string $token,
        array $file,
        string $displayName,
        string $previewHtml,
        string $descriptionHtml,
        string $fileTypeLabel,
        bool $singleAlreadyDownloaded,
        bool $fileLimitReached,
        int $fileLimitCurrent,
        int $fileLimitMax
    ): string {
        $filename = (string) ($file['filename'] ?? '');
        $hasDescription = trim((string) ($file['description'] ?? '')) !== '';
        $singleActionUrl = self::buildShareUrl($share, $token, [
            'klxm_board_share_download' => 'file',
            'file' => $filename,
        ]);

        $detailsId = 'klxm-file-details-' . md5($filename . '|' . $displayName . '|' . (string) ($file['updatedate'] ?? ''));

        $html = '<tr data-search="' . htmlspecialchars(strtolower($displayName . ' ' . $filename . ' ' . (string) ($file['description'] ?? ''))) . '">';
        $html .= '<td class="klxm-col-select" data-label="Auswahl"><label class="klxm-row-select" title="' . htmlspecialchars($displayName) . '"><input class="uk-checkbox klxm-file-checkbox" type="checkbox" name="selected_files[]" value="' . htmlspecialchars($filename) . '" aria-label="Datei ' . htmlspecialchars($displayName) . ' auswählen"' . ($fileLimitReached ? ' disabled' : '') . '> <span class="uk-hidden">Wählen: ' . htmlspecialchars($displayName) . '</span></label></td>';
        $html .= '<td class="klxm-col-preview klxm-row-preview" data-label="Vorschau">' . $previewHtml . '</td>';
        $quotaInfo = '';
        if ($fileLimitMax > 0) {
            $quotaClass = $fileLimitReached ? ' klxm-row-quota--reached' : '';
            $quotaInfo = '<span class="klxm-row-quota' . $quotaClass . '">Kontingent: ' . $fileLimitCurrent . '/' . $fileLimitMax . '</span>';
        }
        $infoIndicator = $hasDescription ? '<span class="klxm-info-indicator" uk-icon="icon: info; ratio: 0.7" aria-hidden="true" title="Beschreibung vorhanden"></span><span class="uk-hidden">Beschreibung vorhanden</span>' : '';
        if ($hasDescription) {
            $html .= '<td data-label="Datei"><button type="button" class="uk-button uk-button-text klxm-title-trigger is-clickable" uk-toggle="target: #' . htmlspecialchars($detailsId) . '" aria-label="Details öffnen: ' . htmlspecialchars($displayName) . '"><span class="klxm-row-title">' . htmlspecialchars($displayName) . '</span><span class="klxm-row-name"><span class="klxm-row-name-text">' . htmlspecialchars($filename) . '</span>' . $infoIndicator . '</span>' . $quotaInfo . '</button></td>';
        } else {
            $html .= '<td data-label="Datei"><div class="klxm-title-trigger"><span class="klxm-row-title">' . htmlspecialchars($displayName) . '</span><span class="klxm-row-name"><span class="klxm-row-name-text">' . htmlspecialchars($filename) . '</span></span>' . $quotaInfo . '</div></td>';
        }
        $html .= '<td class="klxm-col-type" data-label="Typ">' . htmlspecialchars($fileTypeLabel) . '</td>';
        $html .= '<td class="klxm-col-size" data-label="Größe">' . htmlspecialchars(self::formatBytes((int) ($file['filesize'] ?? 0))) . '</td>';
        $html .= '<td class="klxm-col-updated" data-label="Aktualisiert">' . htmlspecialchars(self::formatDateOnly((string) ($file['updatedate'] ?? ''))) . '</td>';
        $html .= '<td class="klxm-col-actions" data-label="Aktionen"><div class="klxm-row-actions">';
        if ($singleAlreadyDownloaded || $fileLimitReached) {
            $disabledReason = $singleAlreadyDownloaded
                ? 'Datei wurde bereits als Einzeldownload geladen.'
                : 'Kontingent für diese Datei ist erreicht.';
            $html .= '<span class="klxm-tooltip-wrap" uk-tooltip="title: ' . htmlspecialchars($disabledReason) . '">';
            $html .= '<button type="button" class="uk-icon-button" disabled title="' . htmlspecialchars($disabledReason) . '" aria-label="' . htmlspecialchars($disabledReason) . '">'
                . '<span class="klxm-download-icon" uk-icon="icon: download"></span>'
                . '<span class="uk-hidden-visually">Download nicht verfügbar</span>'
                . '</button>';
            $html .= '</span>';
        } else {
            $html .= '<button type="submit" class="uk-icon-button klxm-single-download-btn" '
                . 'formaction="' . htmlspecialchars($singleActionUrl) . '" '
                . 'formmethod="post" '
                . 'name="file" '
                . 'value="' . htmlspecialchars($filename) . '" '
                . 'data-disabled-reason="Datei wurde bereits als Einzeldownload geladen." '
                . 'title="Datei herunterladen" '
                . 'aria-label="Datei herunterladen: ' . htmlspecialchars($displayName) . '">'
                . '<span class="klxm-download-icon" uk-icon="icon: download"></span>'
                . '<span class="uk-hidden-visually">Datei herunterladen</span>'
                . '</button>';
        }
        $html .= '</div>';
        if ($hasDescription) {
            $html .= '<div id="' . htmlspecialchars($detailsId) . '" uk-modal>';
            $html .= '<div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical">';
            $html .= '<button type="button" class="uk-modal-close-default" uk-close aria-label="Details schließen"></button>';
            $html .= '<h3 class="uk-modal-title">' . htmlspecialchars($displayName) . '</h3>';
            $html .= '<div class="klxm-file-details-modal">';
            $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Dateiname</span><span class="klxm-file-details-value">' . htmlspecialchars($filename) . '</span></div>';
            $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Größe</span><span class="klxm-file-details-value">' . htmlspecialchars(self::formatBytes((int) ($file['filesize'] ?? 0))) . '</span></div>';
            $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Typ</span><span class="klxm-file-details-value">' . htmlspecialchars($fileTypeLabel) . '</span></div>';
            $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Aktualisiert</span><span class="klxm-file-details-value">' . htmlspecialchars(self::formatDate((string) ($file['updatedate'] ?? ''))) . '</span></div>';
            if ($fileLimitMax > 0) {
                $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Kontingent</span><span class="klxm-file-details-value">' . $fileLimitCurrent . '/' . $fileLimitMax . '</span></div>';
            }
            $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Beschreibung</span><span class="klxm-file-details-value">' . $descriptionHtml . '</span></div>';
            $html .= '</div>';
            $html .= '</div></div>';
        }
        $html .= '</td>';
        $html .= '</tr>';

        return $html;
    }

    private static function renderDescriptionCell(string $description, int $shareId, int $groupIndex, int $fileIndex): string
    {
        $text = trim($description);
        if ($text === '') {
            return '<span class="uk-text-meta">-</span>';
        }
        return nl2br(htmlspecialchars($text));
    }

    /**
     * @param array<string, mixed> $share
     * @param array<string, int> $perFileLimits
     * @param array<string, int> $perFileCounts
     */
    private static function renderTileCard(
        array $share,
        string $token,
        array $file,
        string $displayName,
        string $previewHtml,
        string $descriptionHtml,
        string $fileTypeLabel,
        bool $singleAlreadyDownloaded,
        bool $fileLimitReached,
        int $fileLimitCurrent,
        int $fileLimitMax,
        string $displayMode
    ): string {
        $filename = (string) ($file['filename'] ?? '');
        $hasDescription = trim((string) ($file['description'] ?? '')) !== '';
        $singleActionUrl = self::buildShareUrl($share, $token, [
            'klxm_board_share_download' => 'file',
            'file' => $filename,
        ]);

        $detailsId = 'klxm-file-details-' . md5($filename . '|' . $displayName . '|' . (string) ($file['updatedate'] ?? ''));

        $html = '<article class="uk-card uk-card-default uk-card-hover uk-card-small klxm-file-card klxm-file-card--' . htmlspecialchars($displayMode) . '" data-search="' . htmlspecialchars(strtolower($displayName . ' ' . $filename . ' ' . (string) ($file['description'] ?? ''))) . '">';
        $html .= '<div class="uk-card-media-top klxm-file-preview">' . $previewHtml . '</div>';
        $html .= '<div class="uk-card-body">';
        $html .= '<div class="klxm-file-card-title">' . htmlspecialchars($displayName) . '</div>';
        $html .= '<span class="klxm-file-card-name"><span class="klxm-file-card-name-text">' . htmlspecialchars($filename) . '</span>' . ($hasDescription ? '<span class="klxm-info-indicator" uk-icon="icon: info; ratio: 0.7" aria-hidden="true" title="Beschreibung vorhanden"></span><span class="uk-hidden">Beschreibung vorhanden</span>' : '') . '</span>';
        if ($fileLimitMax > 0) {
            $quotaClass = $fileLimitReached ? ' klxm-file-card-quota--reached' : '';
            $html .= '<span class="klxm-file-card-quota' . $quotaClass . '">Kontingent: ' . $fileLimitCurrent . '/' . $fileLimitMax . '</span>';
        }
        $html .= '<div class="klxm-file-card-meta">';
        $html .= '<span>' . htmlspecialchars(self::formatBytes((int) ($file['filesize'] ?? 0))) . '</span>';
        $html .= '<span>·</span>';
        $html .= '<span>' . htmlspecialchars($fileTypeLabel) . '</span>';
        $html .= '</div>';
        $html .= '<div class="klxm-file-card-actions">';
        $html .= '<label class="uk-form-label" title="' . htmlspecialchars($displayName) . '"><input class="uk-checkbox klxm-file-checkbox" type="checkbox" name="selected_files[]" value="' . htmlspecialchars($filename) . '" aria-label="Datei ' . htmlspecialchars($displayName) . ' auswählen"' . ($fileLimitReached ? ' disabled' : '') . '> <span class="uk-hidden">Wählen: ' . htmlspecialchars($displayName) . '</span></label>';
        $html .= '<div class="klxm-file-card-action-links">';
        if ($hasDescription) {
            $html .= '<button type="button" class="uk-button uk-button-text klxm-details-trigger" uk-toggle="target: #' . htmlspecialchars($detailsId) . '">Mehr Infos</button>';
        }
        if ($singleAlreadyDownloaded || $fileLimitReached) {
            $disabledReason = $singleAlreadyDownloaded
                ? 'Datei wurde bereits als Einzeldownload geladen.'
                : 'Kontingent für diese Datei ist erreicht.';
            $html .= '<span class="klxm-tooltip-wrap" uk-tooltip="title: ' . htmlspecialchars($disabledReason) . '">';
            $html .= '<button type="button" class="uk-icon-button" disabled title="' . htmlspecialchars($disabledReason) . '" aria-label="' . htmlspecialchars($disabledReason) . '">'
                . '<span class="klxm-download-icon" uk-icon="icon: download"></span>'
                . '<span class="uk-hidden-visually">Download nicht verfügbar</span>'
                . '</button>';
            $html .= '</span>';
        } else {
            $html .= '<button type="submit" class="uk-icon-button klxm-single-download-btn" '
                . 'formaction="' . htmlspecialchars($singleActionUrl) . '" '
                . 'formmethod="post" '
                . 'name="file" '
                . 'value="' . htmlspecialchars($filename) . '" '
                . 'data-disabled-reason="Datei wurde bereits als Einzeldownload geladen." '
                . 'title="Datei herunterladen" '
                . 'aria-label="Datei herunterladen: ' . htmlspecialchars($displayName) . '">'
                . '<span class="klxm-download-icon" uk-icon="icon: download"></span>'
                . '<span class="uk-hidden-visually">Datei herunterladen</span>'
                . '</button>';
        }
            $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</article>';
        if ($hasDescription) {
            $html .= '<div id="' . htmlspecialchars($detailsId) . '" uk-modal>';
            $html .= '<div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical">';
            $html .= '<button type="button" class="uk-modal-close-default" uk-close aria-label="Details schließen"></button>';
            $html .= '<h3 class="uk-modal-title">' . htmlspecialchars($displayName) . '</h3>';
            $html .= '<div class="klxm-file-details-modal">';
            $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Dateiname</span><span class="klxm-file-details-value">' . htmlspecialchars($filename) . '</span></div>';
            $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Größe</span><span class="klxm-file-details-value">' . htmlspecialchars(self::formatBytes((int) ($file['filesize'] ?? 0))) . '</span></div>';
            $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Typ</span><span class="klxm-file-details-value">' . htmlspecialchars($fileTypeLabel) . '</span></div>';
            $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Aktualisiert</span><span class="klxm-file-details-value">' . htmlspecialchars(self::formatDate((string) ($file['updatedate'] ?? ''))) . '</span></div>';
            if ($fileLimitMax > 0) {
                $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Kontingent</span><span class="klxm-file-details-value">' . $fileLimitCurrent . '/' . $fileLimitMax . '</span></div>';
            }
            $html .= '<div class="klxm-file-details-row"><span class="klxm-file-details-label">Beschreibung</span><span class="klxm-file-details-value">' . $descriptionHtml . '</span></div>';
            $html .= '</div>';
            $html .= '</div></div>';
        }

        return $html;
    }

    private static function formatCompactFileTypeLabel(string $filename): string
    {
        $extension = strtolower(rex_file::extension($filename));
        if ($extension === '') {
            return 'Datei';
        }

        if (self::isImageExtension($extension)) {
            return 'Bild';
        }
        if ($extension === 'pdf') {
            return 'PDF';
        }
        if (self::isVideoExtension($extension)) {
            return 'Video';
        }
        if (in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'flac'], true)) {
            return 'Audio';
        }
        if (in_array($extension, ['zip', 'gz', 'rar', '7z', 'tar'], true)) {
            return 'Archiv';
        }
        if (in_array($extension, ['xls', 'xlsx', 'csv', 'ods'], true)) {
            return 'Tabelle';
        }
        if (in_array($extension, ['doc', 'docx', 'odt', 'rtf', 'txt', 'md'], true)) {
            return 'Dokument';
        }
        if (in_array($extension, ['json', 'xml', 'yml', 'yaml', 'js', 'ts', 'css', 'scss', 'php', 'html'], true)) {
            return 'Code';
        }

        return strtoupper($extension);
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

            if (in_array($type, ['select', 'radio', 'rating'], true) && $value !== '' && $field['options'] !== [] && !in_array($value, $field['options'], true)) {
                return ['success' => '', 'error' => 'Ungültige Auswahl im Feld "' . $field['label'] . '".'];
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
        $html .= '<style>'
            . '.klxm-radio-group{display:flex;flex-wrap:wrap;gap:12px 16px;align-items:center}'
            . '.klxm-radio-option{display:inline-flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid #dbe4ef;border-radius:999px;background:#fff;line-height:1.2;cursor:pointer;transition:border-color .2s ease,box-shadow .2s ease,background-color .2s ease}'
            . '.klxm-radio-option .uk-radio{margin:0;flex:0 0 auto;vertical-align:middle}'
            . '.klxm-radio-option:hover{border-color:#b9c9dd}'
            . '.klxm-radio-option:has(.uk-radio:focus-visible){box-shadow:0 0 0 3px rgba(30,135,240,.2)}'
            . '.klxm-radio-option:has(.uk-radio:checked){font-weight:700;border-color:#1e87f0;background:#eff6ff}'
            . '.klxm-rating{display:inline-flex;flex-direction:row-reverse;justify-content:flex-end;align-items:center;gap:2px}'
            . '.klxm-rating input[type="radio"]{position:absolute;opacity:0;pointer-events:none;width:1px;height:1px}'
            . '.klxm-rating label{font-size:1.7rem;line-height:1;color:#c9d3df;cursor:pointer;padding:0 2px;transition:color .2s ease,transform .15s ease}'
            . '.klxm-rating label:hover,.klxm-rating label:hover ~ label{color:#f5bf2d}'
            . '.klxm-rating input[type="radio"]:checked ~ label{color:#f5bf2d}'
            . '.klxm-rating input[type="radio"]:focus-visible + label{outline:2px solid rgba(30,135,240,.55);outline-offset:2px;border-radius:3px}'
            . '@media (max-width:639px){.klxm-radio-group{gap:10px 12px}.klxm-radio-option{padding:7px 10px;font-size:.95rem}}'
            . '</style>';
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
            $labelForId = $fieldId;
            if ($field['type'] === 'rating') {
                $labelForId = $fieldId . '_star_1';
            }

            $html .= '<div class="uk-margin">';
            if ($field['type'] !== 'checkbox') {
                $html .= '<label class="uk-form-label" for="' . htmlspecialchars($labelForId) . '">' . htmlspecialchars($field['label']) . $requiredMark . '</label>';
            }
            $html .= '<div class="uk-form-controls">';

            if ($field['type'] === 'textarea') {
                $html .= '<textarea class="uk-textarea" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($field['key']) . '"' . $requiredAttr . '></textarea>';
            } elseif ($field['type'] === 'checkbox') {
                $html .= '<label><input class="uk-checkbox" type="checkbox" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($field['key']) . '" value="1"' . $requiredAttr . '> ' . htmlspecialchars($field['label']) . $requiredMark . '</label>';
            } elseif ($field['type'] === 'radio') {
                $html .= '<div class="klxm-radio-group" role="radiogroup" aria-label="' . htmlspecialchars($field['label']) . '">';
                foreach ($field['options'] as $optionIndex => $option) {
                    $optionId = $fieldId . '_opt_' . $optionIndex;
                    $requiredOptionAttr = ($field['required'] && $optionIndex === 0) ? ' required' : '';
                    $html .= '<label class="klxm-radio-option" for="' . htmlspecialchars($optionId) . '">';
                    $html .= '<input class="uk-radio" type="radio" id="' . htmlspecialchars($optionId) . '" name="' . htmlspecialchars($field['key']) . '" value="' . htmlspecialchars($option) . '"' . $requiredOptionAttr . '>';
                    $html .= htmlspecialchars($option) . '</label>';
                }
                $html .= '</div>';
            } elseif ($field['type'] === 'rating') {
                $html .= '<div class="klxm-rating" role="radiogroup" aria-label="' . htmlspecialchars($field['label']) . '">';
                foreach (array_reverse($field['options']) as $optionIndex => $option) {
                    $optionId = $fieldId . '_star_' . ($optionIndex + 1);
                    $requiredOptionAttr = ($field['required'] && $optionIndex === (count($field['options']) - 1)) ? ' required' : '';
                    $html .= '<input type="radio" id="' . htmlspecialchars($optionId) . '" name="' . htmlspecialchars($field['key']) . '" value="' . htmlspecialchars($option) . '"' . $requiredOptionAttr . '>';
                    $html .= '<label for="' . htmlspecialchars($optionId) . '" title="' . htmlspecialchars($option) . ' Sterne" aria-label="' . htmlspecialchars($option) . ' Sterne">★</label>';
                }
                $html .= '</div>';
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

        $allowedTypes = ['text', 'textarea', 'checkbox', 'select', 'radio', 'rating'];
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
            if (in_array($type, ['select', 'radio', 'rating'], true)) {
                $rawOptions = (string) ($field['options'] ?? '');
                foreach (explode('|', $rawOptions) as $opt) {
                    $opt = trim($opt);
                    if ($opt !== '') {
                        $options[] = $opt;
                    }
                }

                if ($type === 'rating') {
                    if ($options === []) {
                        $options = ['1', '2', '3', '4', '5'];
                    }

                    $normalized = [];
                    foreach ($options as $option) {
                        if (ctype_digit($option) && (int) $option > 0) {
                            $normalized[] = (string) (int) $option;
                        }
                    }
                    $normalized = array_values(array_unique($normalized));
                    sort($normalized, SORT_NUMERIC);
                    $options = $normalized !== [] ? $normalized : ['1', '2', '3', '4', '5'];
                }

                if ($type === 'radio' && $options === []) {
                    $options = ['Ja', 'Nein'];
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
    private static function downloadSingleFile(array $share, string $filename, string $token, ?int $requestId = null): never
    {
        if ($filename === '' || basename($filename) !== $filename || str_contains($filename, '/') || str_contains($filename, '\\')) {
            self::sendText('Ungültiger Dateiname.', rex_response::HTTP_BAD_REQUEST);
        }

        $allowed = self::collectDownloadableFilenames($share);
        if (!in_array($filename, $allowed, true)) {
            self::redirectToShareWithMessage($share, $token, 'Diese Datei ist nicht mehr zum Download verfügbar (nicht freigegeben oder Kontingent erreicht).', 'warning');
        }

        $shareId = (int) ($share['id'] ?? 0);
        if (self::hasSingleFileDownloadBeenUsed($shareId, $filename)) {
            self::redirectToShareWithMessage($share, $token, 'Diese Datei wurde bereits als Einzeldownload geladen.', 'warning');
        }

        $path = rex_path::media($filename);
        if (!is_file($path)) {
            self::redirectToShareWithMessage($share, $token, 'Die Datei wurde nicht gefunden.', 'warning');
        }

        self::increaseDownloadCount((int) $share['id']);
        self::recordFileDownloadEvents((int) $share['id'], (int) ($share['article_id'] ?? 0), [$filename], 'file', $requestId);
        self::markSingleFileDownloaded($shareId, $filename);
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

    /**
     * @param array<string, mixed> $share
     * @return string[]
     */
    private static function collectDownloadableFilenames(array $share): array
    {
        $allowed = self::collectAllowedFilenames($share);
        $limits = self::decodeFileDownloadLimits($share);
        if ($limits === []) {
            return $allowed;
        }

        $counts = self::getPerFileDownloadCountMap((int) ($share['id'] ?? 0));
        $result = [];
        foreach ($allowed as $filename) {
            $max = (int) ($limits[$filename] ?? 0);
            if ($max > 0 && (int) ($counts[$filename] ?? 0) >= $max) {
                continue;
            }
            $result[] = $filename;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $share
     * @return array<string, int>
     */
    private static function decodeFileDownloadLimits(array $share): array
    {
        $raw = trim((string) ($share['file_download_limits_json'] ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $limits = [];
        foreach ($decoded as $filename => $max) {
            if (!is_string($filename) || $filename === '') {
                continue;
            }
            if (basename($filename) !== $filename || str_contains($filename, '/') || str_contains($filename, '\\')) {
                continue;
            }
            $maxValue = (int) $max;
            if ($maxValue > 0) {
                $limits[$filename] = $maxValue;
            }
        }

        return $limits;
    }

    /**
     * @param array<string, int> $limits
     */
    private static function encodeFileDownloadLimits(array $limits): string
    {
        $clean = [];
        foreach ($limits as $filename => $max) {
            if (!is_string($filename) || $filename === '') {
                continue;
            }
            if (basename($filename) !== $filename || str_contains($filename, '/') || str_contains($filename, '\\')) {
                continue;
            }
            $maxValue = (int) $max;
            if ($maxValue > 0) {
                $clean[$filename] = $maxValue;
            }
        }

        if ($clean === []) {
            return '[]';
        }

        ksort($clean);
        return (string) json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, int>
     */
    private static function getPerFileDownloadCountMap(int $shareId): array
    {
        if ($shareId <= 0) {
            return [];
        }

        $rows = rex_sql::factory()->getArray(
            'SELECT filename, COUNT(*) AS cnt FROM ' . rex::getTable('klxm_restricted_file_share_download') . ' WHERE share_id = ? GROUP BY filename',
            [$shareId]
        );

        $result = [];
        foreach ($rows as $row) {
            $filename = (string) ($row['filename'] ?? '');
            if ($filename === '') {
                continue;
            }
            $result[$filename] = (int) ($row['cnt'] ?? 0);
        }

        return $result;
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
     * @return array<string, true>
     */
    private static function getSingleDownloadedFiles(int $shareId): array
    {
        if ($shareId <= 0) {
            return [];
        }

        rex_login::startSession();
        $raw = (string) rex_session(self::getSingleDownloadSessionKey($shareId), 'string', '');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $filename) {
            if (!is_string($filename) || $filename === '') {
                continue;
            }
            if (basename($filename) !== $filename || str_contains($filename, '/') || str_contains($filename, '\\')) {
                continue;
            }
            $result[$filename] = true;
        }

        return $result;
    }

    private static function hasSingleFileDownloadBeenUsed(int $shareId, string $filename): bool
    {
        if ($shareId <= 0 || $filename === '') {
            return false;
        }

        return isset(self::getSingleDownloadedFiles($shareId)[$filename]);
    }

    private static function markSingleFileDownloaded(int $shareId, string $filename): void
    {
        if ($shareId <= 0 || $filename === '') {
            return;
        }

        $files = self::getSingleDownloadedFiles($shareId);
        $files[$filename] = true;
        rex_set_session(self::getSingleDownloadSessionKey($shareId), (string) json_encode(array_keys($files), JSON_UNESCAPED_UNICODE));
    }

    private static function getSingleDownloadSessionKey(int $shareId): string
    {
        return 'klxm_restricted_file_share_single_downloaded_' . $shareId;
    }

    private static function getShareUiMessageSessionKey(int $shareId): string
    {
        return 'klxm_restricted_file_share_ui_message_' . $shareId;
    }

    private static function pushShareUiMessage(int $shareId, string $message, string $type = 'warning'): void
    {
        if ($shareId <= 0 || trim($message) === '') {
            return;
        }

        $allowedTypes = ['success', 'warning', 'danger', 'primary'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'warning';
        }

        rex_login::startSession();
        rex_set_session(
            self::getShareUiMessageSessionKey($shareId),
            (string) json_encode([
                'message' => $message,
                'type' => $type,
            ], JSON_UNESCAPED_UNICODE)
        );
    }

    private static function consumeShareUiMessageHtml(int $shareId): string
    {
        if ($shareId <= 0) {
            return '';
        }

        rex_login::startSession();
        $key = self::getShareUiMessageSessionKey($shareId);
        $raw = (string) rex_session($key, 'string', '');
        if ($raw === '') {
            return '';
        }

        rex_unset_session($key);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }

        $message = trim((string) ($decoded['message'] ?? ''));
        if ($message === '') {
            return '';
        }

        $type = (string) ($decoded['type'] ?? 'warning');
        $allowedTypes = ['success', 'warning', 'danger', 'primary'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'warning';
        }

        return '<div class="uk-alert-' . $type . ' uk-margin-top" uk-alert>' . htmlspecialchars($message) . '</div>';
    }

    /**
     * @param array<string, mixed> $share
     */
    private static function redirectToShareWithMessage(array $share, string $token, string $message, string $type = 'warning'): never
    {
        $shareId = (int) ($share['id'] ?? 0);
        self::pushShareUiMessage($shareId, $message, $type);

        rex_response::cleanOutputBuffers();
        rex_response::sendRedirect(self::buildCurrentShareUrl($token, []), 303);
        exit;
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
            if ($articleId !== null && (int) ($share['article_id'] ?? 0) !== $articleId) {
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

        if ($articleId !== null && (int) ($share['article_id'] ?? 0) !== $articleId) {
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
        // Klassische Direktfreigaben sind abgeschaltet.
        return false;
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
        if (!isset($params['klxm_display'])) {
            $currentDisplay = strtolower(trim(rex_request::get('klxm_display', 'string', '')));
            if (in_array($currentDisplay, ['compact', 'detail'], true)) {
                $currentDisplay = 'list';
            }
            if (in_array($currentDisplay, ['list', 'tiles'], true)) {
                $params['klxm_display'] = $currentDisplay;
            }
        }

        if (!isset($params['klxm_sort'])) {
            $currentSort = strtolower(trim(rex_request::get('klxm_sort', 'string', '')));
            if (in_array($currentSort, ['manual', 'asc', 'desc'], true)) {
                if ($currentSort !== 'manual') {
                    $params['klxm_sort'] = $currentSort;
                }
            }
        }

        $query = array_merge(['klxm_board_share' => $token], $params);
        $articleId = (int) ($share['article_id'] ?? 0);

        if ($articleId <= 0) {
            $path = self::normalizeFrontendPath(rex_url::frontendController([], false));
            if (!rex::isBackend()) {
                $requestPath = (string) parse_url((string) rex_request::server('REQUEST_URI', 'string', ''), PHP_URL_PATH);
                if ($requestPath !== '') {
                    $path = $requestPath;
                }
            }
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
        if ($articleId <= 0) {
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
            return '<a href="' . htmlspecialchars($previewUrl) . '" class="klxm-preview-link" data-type="image" data-caption="' . htmlspecialchars($caption) . '" aria-label="Vorschau öffnen: ' . htmlspecialchars($caption) . '">' . $thumb . '</a>';
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
                return '<a href="' . htmlspecialchars($largePreviewUrl) . '" class="klxm-preview-link" data-type="image" data-caption="' . htmlspecialchars($caption) . '" aria-label="Vorschau öffnen: ' . htmlspecialchars($caption) . '">' . $thumb . '</a>';
            }

            return '<div class="klxm-filetype-tile">'
                . self::renderFileTypeIconSvg()
                . '<span class="klxm-filetype-label">PDF</span>'
                . '</div>';
        }

            if (self::isVideoExtension($extension)) {
                return '<a href="' . htmlspecialchars($previewUrl) . '" class="klxm-preview-link" data-type="video" data-caption="' . htmlspecialchars($caption) . '" aria-label="Vorschau öffnen: ' . htmlspecialchars($caption) . '">'
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

    private static function renderToolbarIconSvg(string $type): string
    {
        if ($type === 'tiles') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 3h8v8H3V3Zm10 0h8v8h-8V3ZM3 13h8v8H3v-8Zm10 0h8v8h-8v-8Z"/></svg>';
        }

        if ($type === 'sort') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M7 4h2v12h3l-4 4-4-4h3V4Zm7 2h7v2h-7V6Zm0 5h5v2h-5v-2Zm0 5h3v2h-3v-2Z"/></svg>';
        }

        if ($type === 'jump') {
            return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h2v2H4V6Zm4 0h12v2H8V6ZM4 11h2v2H4v-2Zm4 0h12v2H8v-2ZM4 16h2v2H4v-2Zm4 0h12v2H8v-2Z"/></svg>';
        }

        return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16v2H4V6Zm0 5h16v2H4v-2Zm0 5h16v2H4v-2Z"/></svg>';
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

    private static function formatDateOnly(string $date): string
    {
        if ($date === '' || $date === '0000-00-00 00:00:00') {
            return '-';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        return date('d.m.Y', $timestamp);
    }

    private static function sendText(string $text, string $statusCode): never
    {
        $status = (int) $statusCode;
        $alertClass = 'uk-alert-primary';
        $title = 'Hinweis';

        if ($status >= 500) {
            $alertClass = 'uk-alert-danger';
            $title = 'Fehler';
        } elseif ($status === 403) {
            $alertClass = 'uk-alert-danger';
            $title = 'Kein Zugriff';
        } elseif ($status === 404) {
            $alertClass = 'uk-alert-warning';
            $title = 'Nicht gefunden';
        } elseif ($status >= 400) {
            $alertClass = 'uk-alert-warning';
            $title = 'Hinweis';
        }

        $branding = self::getShareBranding();
        $html = self::renderShareBaseStyles($branding['accent']);
        $html .= '<section class="uk-section uk-section-small"><div class="uk-container"><div class="uk-card uk-card-default uk-card-body">';
        $html .= self::renderShareBrandingHeader($branding);
        $html .= '<h3>' . htmlspecialchars($title) . '</h3>';
        $html .= '<div class="' . $alertClass . '" uk-alert>' . htmlspecialchars($text) . '</div>';
        $html .= '</div></div></section>';

        rex_response::cleanOutputBuffers();
        rex_response::setStatus($statusCode);
        rex_response::sendCacheControl('no-store, no-cache, must-revalidate');
        rex_response::sendContent('<!doctype html><meta charset="utf-8">' . $html, 'text/html; charset=utf-8');
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
