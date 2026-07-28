<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use KLXM\Restricted\Media\BoardShareService;
use rex;
use rex_addon;
use rex_article;
use rex_csrf_token;
use rex_media_category;
use rex_request;
use rex_sql;
use rex_url;
use rex_view;

$user = rex::requireUser();
if (!$user->isAdmin() && !$user->hasPerm('klxm_restricted[share]')) {
    echo rex_view::error('Keine Berechtigung fuer Dateifreigaben.');
    return;
}

$addon = rex_addon::get('klxm_restricted');
rex_view::addJsFile($addon->getAssetsUrl('share-links.js'));
rex_view::addJsFile($addon->getAssetsUrl('file-share.js'));

$csrf = rex_csrf_token::factory('klxm_restricted_file_share');
$func = rex_request('func', 'string', '');
$shareMode = rex_request('share_mode', 'string', 'article');
$selectedCategoryId = rex_request('media_category_id', 'int', 0);
$sourceMode = rex_request('source_mode', 'string', 'category');
$selectedArticleId = rex_request('article_id', 'int', 0);

if ($selectedCategoryId <= 0) {
    $rootMediaCategories = rex_media_category::getRootCategories(false);
    if ($rootMediaCategories !== []) {
        $selectedCategoryId = (int) $rootMediaCategories[0]->getId();
    }
}

$defaultRequestValidDays = (int) rex_addon::get('klxm_restricted')->getConfig('share_request_valid_days', 3);
if ($defaultRequestValidDays <= 0) {
    $defaultRequestValidDays = 3;
}

$permissionManager = new PermissionManager();
$getMediaCategoryProtectionInfo = static function (rex_media_category $category) use ($permissionManager): array {
    $effectiveRoles = $permissionManager->getInheritedRolesForMediaCategory($category->getId());
    $isProtected = $effectiveRoles !== [] && !in_array(PermissionManager::ROLE_PUBLIC, $effectiveRoles, true);

    return [
        'icon_class' => $isProtected ? 'fa-lock' : 'fa-circle-o',
        'icon_style' => $isProtected ? '' : 'color:#9aa3ad;',
    ];
};

$buildMediaCategoryOptions = static function (array $categories, int $level = 0) use (&$buildMediaCategoryOptions, $getMediaCategoryProtectionInfo): string {
    $html = '';
    foreach ($categories as $category) {
        if (!$category instanceof rex_media_category) {
            continue;
        }

        $protectionInfo = $getMediaCategoryProtectionInfo($category);
        $prefix = str_repeat('&nbsp;&nbsp;', $level);
        $label = $category->getName();
        $iconStyleAttr = $protectionInfo['icon_style'] !== '' ? ' style="' . $protectionInfo['icon_style'] . '"' : '';
        $dataContent = '<span class="fa ' . $protectionInfo['icon_class'] . '"' . $iconStyleAttr . ' aria-hidden="true"></span> ' . $prefix . htmlspecialchars($label);
        $html .= '<option value="' . $category->getId() . '" data-content="' . htmlspecialchars($dataContent) . '">' . $prefix . htmlspecialchars($label) . '</option>';
        $html .= $buildMediaCategoryOptions($category->getChildren(false), $level + 1);
    }

    return $html;
};

$mediaCategoryOptionsHtml = $buildMediaCategoryOptions(rex_media_category::getRootCategories(false));

$buildMediaCategoryOptionsSelected = static function (array $categories, int $selectedId, int $level = 0) use (&$buildMediaCategoryOptionsSelected, $getMediaCategoryProtectionInfo): string {
    $html = '';
    foreach ($categories as $category) {
        if (!$category instanceof rex_media_category) {
            continue;
        }

        $protectionInfo = $getMediaCategoryProtectionInfo($category);
        $prefix = str_repeat('&nbsp;&nbsp;', $level);
        $isSelected = $category->getId() === $selectedId ? ' selected' : '';
        $label = $category->getName();
        $iconStyleAttr = $protectionInfo['icon_style'] !== '' ? ' style="' . $protectionInfo['icon_style'] . '"' : '';
        $dataContent = '<span class="fa ' . $protectionInfo['icon_class'] . '"' . $iconStyleAttr . ' aria-hidden="true"></span> ' . $prefix . htmlspecialchars($label);
        $html .= '<option value="' . $category->getId() . '" data-content="' . htmlspecialchars($dataContent) . '"' . $isSelected . '>' . $prefix . htmlspecialchars($label) . '</option>';
        $html .= $buildMediaCategoryOptionsSelected($category->getChildren(false), $selectedId, $level + 1);
    }

    return $html;
};

$decodeRequestFields = static function (string $rawJson): array {
    if ($rawJson === '') {
        return [];
    }

    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $rows = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rows[] = [
            'key' => trim((string) ($row['key'] ?? '')),
            'label' => trim((string) ($row['label'] ?? '')),
            'type' => trim((string) ($row['type'] ?? 'text')),
            'required' => (int) ($row['required'] ?? 0) === 1 ? '1' : '0',
            'options' => trim((string) ($row['options'] ?? '')),
        ];
    }

    return $rows;
};

$decodeGroupedFiles = static function (string $rawJson): array {
    if ($rawJson === '') {
        return [];
    }

    $decoded = json_decode($rawJson, true);
    return is_array($decoded) ? $decoded : [];
};

$editShareId = 0;
$editShare = null;
if ($func === 'edit_share') {
    $editShareId = rex_request('share_id', 'int', 0);
    if ($editShareId > 0) {
        $editShare = BoardShareService::getShareById($editShareId);
        if ($editShare === null) {
            echo rex_view::error('Freigabe zum Bearbeiten nicht gefunden.');
            $editShareId = 0;
        } else {
            $shareModeRequest = rex_request('share_mode', 'string', '');
            if ($shareModeRequest === 'article' || $shareModeRequest === 'direct') {
                $shareMode = $shareModeRequest;
            } else {
                $shareMode = (string) ($editShare['share_mode'] ?? 'article');
            }

            $sourceModeRequest = rex_request('source_mode', 'string', '');
            if ($sourceModeRequest === 'category' || $sourceModeRequest === 'manual' || $sourceModeRequest === 'categorized') {
                $sourceMode = $sourceModeRequest;
            } else {
                $sourceMode = (string) ($editShare['source_mode'] ?? 'category');
            }

            $selectedArticleId = (int) ($editShare['article_id'] ?? 0);

            $requestCategoryId = rex_request('media_category_id', 'int', 0);
            if ($requestCategoryId > 0) {
                $selectedCategoryId = $requestCategoryId;
            } else {
                $selectedCategoryId = (int) ($editShare['media_category_id'] ?? 0);
            }

            if ($selectedCategoryId <= 0) {
                $rootMediaCategories = rex_media_category::getRootCategories(false);
                if ($rootMediaCategories !== []) {
                    $selectedCategoryId = (int) $rootMediaCategories[0]->getId();
                }
            }
        }
    }
}

$defaultFormData = [
    'title' => '',
    'description' => '',
    'expires_at' => '',
    'max_downloads' => '0',
    'file_download_limit_rows' => [],
    'allow_zip' => true,
    'request_enabled' => true,
    'request_valid_days' => (string) $defaultRequestValidDays,
    'request_intro_text' => 'Bitte Formular ausfüllen. Danach senden wir den Freigabelink per E-Mail.',
    'manual_files' => [],
    'manual_groups' => [],
    'categorized_groups' => [],
    'request_fields' => [],
];

if (is_array($editShare)) {
    $defaultFormData['title'] = (string) ($editShare['title'] ?? '');
    $defaultFormData['description'] = (string) ($editShare['description'] ?? '');
    $defaultFormData['expires_at'] = (string) ($editShare['expires_at'] ?? '');
    $defaultFormData['max_downloads'] = (string) ((int) ($editShare['max_downloads'] ?? 0));
    $defaultFormData['file_download_limit_rows'] = [];
    $rawFileLimits = trim((string) ($editShare['file_download_limits_json'] ?? ''));
    if ($rawFileLimits !== '') {
        $decodedFileLimits = json_decode($rawFileLimits, true);
        if (is_array($decodedFileLimits)) {
            foreach ($decodedFileLimits as $filename => $maxDownloadsPerFile) {
                if (!is_string($filename) || $filename === '') {
                    continue;
                }
                $maxPerFile = (int) $maxDownloadsPerFile;
                if ($maxPerFile <= 0) {
                    continue;
                }
                $defaultFormData['file_download_limit_rows'][] = [
                    'medialist' => $filename,
                    'max' => (string) $maxPerFile,
                ];
            }
        }
    }
    $defaultFormData['allow_zip'] = (int) ($editShare['allow_zip'] ?? 0) === 1;
    $defaultFormData['request_enabled'] = (int) ($editShare['request_enabled'] ?? 0) === 1;
    $defaultFormData['request_valid_days'] = (string) max(1, (int) ($editShare['request_valid_days'] ?? $defaultRequestValidDays));
    $defaultFormData['request_intro_text'] = trim((string) ($editShare['request_intro_text'] ?? '')) !== ''
        ? (string) $editShare['request_intro_text']
        : $defaultFormData['request_intro_text'];
    $defaultFormData['request_fields'] = $decodeRequestFields((string) ($editShare['request_form_json'] ?? ''));

    $groupedFiles = $decodeGroupedFiles((string) ($editShare['grouped_files'] ?? ''));
    if ($sourceMode === 'manual') {
        $manualFiles = [];
        $manualGroups = [];
        foreach ($groupedFiles as $group) {
            if (!is_array($group)) {
                continue;
            }
            $groupName = trim((string) ($group['name'] ?? 'Allgemein'));
            $files = $group['files'] ?? [];
            if (!is_array($files)) {
                continue;
            }
            foreach ($files as $file) {
                if (!is_string($file) || $file === '') {
                    continue;
                }
                $manualFiles[] = $file;
                $manualGroups[$file] = $groupName;
            }
        }
        $defaultFormData['manual_files'] = array_values(array_unique($manualFiles));
        $defaultFormData['manual_groups'] = $manualGroups;
    }

    if ($sourceMode === 'categorized' && is_array($groupedFiles)) {
        $defaultFormData['categorized_groups'] = $groupedFiles;
    }
}

if ($func === 'delete_share') {
    if (!$csrf->isValid()) {
        echo rex_view::error('Aktion abgelehnt (ungueltiger CSRF-Token).');
    } else {
        $shareId = rex_request('share_id', 'int', 0);
        if ($shareId > 0) {
            BoardShareService::deleteShare($shareId);
            echo rex_view::success('Freigabe wurde entfernt.');
        }
    }
}

$createdShareUrl = '';
if (rex_request('create_file_share', 'int', 0) === 1) {
    if (!$csrf->isValid()) {
        echo rex_view::error('Aktion abgelehnt (ungueltiger CSRF-Token).');
    } else {
        $articleId = rex_request('article_id', 'int', 0);
        $editShareIdPost = rex_request('edit_share_id', 'int', 0);
        $shareMode = rex_request('share_mode', 'string', 'article');
        $sourceMode = rex_request('source_mode', 'string', 'category');
        $mediaCategoryId = rex_request('media_category_id', 'int', 0);
        $manualFiles = rex_request('manual_files', 'array', []);
        $manualGroups = rex_request('manual_groups', 'array', []);
        $title = trim(rex_request('share_title', 'string', ''));
        $description = trim(rex_request('share_description', 'string', ''));
        $password = rex_request('share_password', 'string', '');
        $clearPassword = rex_request('clear_password', 'int', 0) === 1;
        $expiresRaw = trim(rex_request('expires_at', 'string', ''));
        $allowZip = rex_request('allow_zip', 'int', 0) === 1;
        $maxDownloads = rex_request('max_downloads', 'int', 0);
        $fileLimitMedialists = rex_request('file_limit_medialist', 'array', []);
        $fileLimitValues = rex_request('file_limit_value', 'array', []);
        $requestIntroText = trim(rex_request('request_intro_text', 'string', ''));
        $requestEnabled = rex_request('request_enabled', 'int', 0) === 1;
        $requestValidDays = rex_request('request_valid_days', 'int', $defaultRequestValidDays);
        $requestFieldKeys = rex_request('request_field_key', 'array', []);
        $requestFieldLabels = rex_request('request_field_label', 'array', []);
        $requestFieldTypes = rex_request('request_field_type', 'array', []);
        $requestFieldRequired = rex_request('request_field_required', 'array', []);
        $requestFieldOptions = rex_request('request_field_options', 'array', []);
        $categorizedGroupNames = rex_request('categorized_group_name', 'array', []);
        $categorizedGroupSources = rex_request('categorized_group_source', 'array', []);
        $categorizedGroupCategoryIds = rex_request('categorized_group_media_category_id', 'array', []);
        $categorizedGroupManualFiles = rex_request('categorized_group_manual_files', 'array', []);
        $categorizedGroupManualMedialists = rex_request('categorized_group_manual_medialist', 'array', []);

        $fileDownloadLimits = [];
        $fileLimitRowCount = max(count($fileLimitMedialists), count($fileLimitValues));
        for ($i = 0; $i < $fileLimitRowCount; $i++) {
            $medialistRaw = isset($fileLimitMedialists[$i]) && is_string($fileLimitMedialists[$i]) ? trim($fileLimitMedialists[$i]) : '';
            $maxRaw = isset($fileLimitValues[$i]) && is_string($fileLimitValues[$i]) ? trim($fileLimitValues[$i]) : '';

            if ($medialistRaw === '' || $maxRaw === '' || !ctype_digit($maxRaw)) {
                continue;
            }

            $maxValue = (int) $maxRaw;
            if ($maxValue <= 0) {
                continue;
            }

            $selectedFiles = array_filter(array_map('trim', explode(',', $medialistRaw)), static fn (string $value): bool => $value !== '');
            foreach ($selectedFiles as $selectedFile) {
                if (basename($selectedFile) !== $selectedFile || str_contains($selectedFile, '/') || str_contains($selectedFile, '\\')) {
                    continue;
                }
                $fileDownloadLimits[$selectedFile] = $maxValue;
            }
        }

        $requestFields = [];
        $rowCount = max(count($requestFieldKeys), count($requestFieldLabels), count($requestFieldTypes));
        $allowedTypes = ['text', 'textarea', 'checkbox', 'select', 'radio', 'rating'];
        for ($i = 0; $i < $rowCount; $i++) {
            $key = isset($requestFieldKeys[$i]) && is_string($requestFieldKeys[$i]) ? trim($requestFieldKeys[$i]) : '';
            $label = isset($requestFieldLabels[$i]) && is_string($requestFieldLabels[$i]) ? trim($requestFieldLabels[$i]) : '';
            $type = isset($requestFieldTypes[$i]) && is_string($requestFieldTypes[$i]) ? trim($requestFieldTypes[$i]) : 'text';
            $requiredRaw = $requestFieldRequired[$i] ?? '0';
            $options = isset($requestFieldOptions[$i]) && is_string($requestFieldOptions[$i]) ? trim($requestFieldOptions[$i]) : '';

            if ($key === '' || $label === '' || !in_array($type, $allowedTypes, true)) {
                continue;
            }

            $requestFields[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => ((string) $requiredRaw === '1' || (string) $requiredRaw === 'on') ? 1 : 0,
                'options' => $options,
            ];
        }

        if ($shareMode !== 'article' && $shareMode !== 'direct') {
            echo rex_view::error('Ungültiger Freigabe-Modus.');
        } elseif ($shareMode === 'article' && ($articleId <= 0 || !rex_article::get($articleId))) {
            echo rex_view::error('Bitte eine gültige REDAXO-Seite für die Ausgabe wählen.');
        } elseif ($sourceMode !== 'category' && $sourceMode !== 'manual' && $sourceMode !== 'categorized') {
            echo rex_view::error('Ungueltiger Quellenmodus.');
        } elseif ($mediaCategoryId <= 0) {
            echo rex_view::error('Bitte eine Medienpool-Kategorie waehlen.');
        } else {
            $availableRows = BoardShareService::getMediaForCategory($mediaCategoryId);
            $allowedFiles = [];
            foreach ($availableRows as $row) {
                $allowedFiles[] = $row['filename'];
            }

            $validManualFiles = [];
            foreach ($manualFiles as $filename) {
                if (!is_string($filename) || $filename === '') {
                    continue;
                }

                if (in_array($filename, $allowedFiles, true)) {
                    $validManualFiles[] = $filename;
                }
            }
            $validManualFiles = array_values(array_unique($validManualFiles));

            $categorizedGroups = [];
            if ($sourceMode === 'categorized') {
                $groupKeys = array_values(array_unique(array_merge(
                    array_keys($categorizedGroupNames),
                    array_keys($categorizedGroupSources),
                    array_keys($categorizedGroupCategoryIds),
                    array_keys($categorizedGroupManualFiles),
                    array_keys($categorizedGroupManualMedialists)
                )));
                sort($groupKeys);

                foreach ($groupKeys as $groupKey) {
                    $groupName = isset($categorizedGroupNames[$groupKey]) && is_string($categorizedGroupNames[$groupKey]) ? trim($categorizedGroupNames[$groupKey]) : '';
                    $groupSource = isset($categorizedGroupSources[$groupKey]) && is_string($categorizedGroupSources[$groupKey]) ? trim($categorizedGroupSources[$groupKey]) : 'manual';
                    $groupCategoryId = isset($categorizedGroupCategoryIds[$groupKey]) ? (int) $categorizedGroupCategoryIds[$groupKey] : 0;

                    if ($groupName === '') {
                        continue;
                    }

                    if ($groupSource === 'media_category') {
                        if ($groupCategoryId <= 0) {
                            continue;
                        }

                        $categorizedGroups[] = [
                            'name' => $groupName,
                            'source' => 'media_category',
                            'media_category_id' => $groupCategoryId,
                            'files' => [],
                        ];
                        continue;
                    }

                    $manualList = [];
                    $rawManual = $categorizedGroupManualFiles[$groupKey] ?? [];
                    if (is_array($rawManual)) {
                        foreach ($rawManual as $item) {
                            if (!is_string($item) || $item === '') {
                                continue;
                            }
                            if (in_array($item, $allowedFiles, true)) {
                                $manualList[] = $item;
                            }
                        }
                    }

                    if ($manualList === []) {
                        $rawMedialist = $categorizedGroupManualMedialists[$groupKey] ?? '';
                        if (is_string($rawMedialist) && trim($rawMedialist) !== '') {
                            $rawItems = explode(',', $rawMedialist);
                            foreach ($rawItems as $item) {
                                $filename = trim((string) $item);
                                if ($filename === '') {
                                    continue;
                                }
                                if (in_array($filename, $allowedFiles, true)) {
                                    $manualList[] = $filename;
                                }
                            }
                        }
                    }

                    $manualList = array_values(array_unique($manualList));
                    if ($manualList === []) {
                        continue;
                    }

                    $categorizedGroups[] = [
                        'name' => $groupName,
                        'source' => 'manual',
                        'media_category_id' => 0,
                        'files' => $manualList,
                    ];
                }
            }

            if ($sourceMode === 'manual' && $validManualFiles === []) {
                echo rex_view::error('Bitte bei manueller Gruppierung mindestens eine Datei auswaehlen.');
            } elseif ($sourceMode === 'categorized' && $categorizedGroups === []) {
                echo rex_view::error('Bitte mindestens einen gültigen Kategorien-Block anlegen.');
            } else {
                $expiresAt = null;
                if ($expiresRaw !== '') {
                    $expiresTimestamp = strtotime($expiresRaw);
                    if ($expiresTimestamp !== false) {
                        $expiresAt = date('Y-m-d H:i:s', $expiresTimestamp);
                    }
                }

                if ($editShareIdPost > 0) {
                    BoardShareService::updateShare(
                        $editShareIdPost,
                        $shareMode,
                        $shareMode === 'article' ? $articleId : 0,
                        $sourceMode,
                        $mediaCategoryId,
                        $validManualFiles,
                        is_array($manualGroups) ? $manualGroups : [],
                        $categorizedGroups,
                        $requestEnabled,
                        max(1, $requestValidDays),
                        $requestFields,
                        $requestIntroText !== '' ? $requestIntroText : null,
                        $title !== '' ? $title : null,
                        $description !== '' ? $description : null,
                        $allowZip,
                        $password !== '' ? $password : null,
                        $clearPassword,
                        $expiresAt,
                        $maxDownloads > 0 ? $maxDownloads : null,
                        $fileDownloadLimits
                    );

                    $updatedShare = BoardShareService::getShareById($editShareIdPost);
                    $tokenPlain = trim((string) ($updatedShare['token_plain'] ?? ''));
                    if ($tokenPlain !== '') {
                        $relativeUrl = BoardShareService::buildPublicShareUrl($shareMode, $shareMode === 'article' ? $articleId : 0, $tokenPlain);
                        $createdShareUrl = rtrim((string) rex::getServer(), '/') . $relativeUrl;
                    }
                    echo rex_view::success('Dateifreigabe wurde aktualisiert.');
                    $editShareId = 0;
                    $editShare = null;
                    $defaultFormData['title'] = '';
                    $defaultFormData['description'] = '';
                    $defaultFormData['expires_at'] = '';
                    $defaultFormData['max_downloads'] = '0';
                    $defaultFormData['file_download_limit_rows'] = [];
                    $defaultFormData['allow_zip'] = true;
                    $defaultFormData['request_enabled'] = true;
                    $defaultFormData['request_valid_days'] = (string) $defaultRequestValidDays;
                    $defaultFormData['request_intro_text'] = 'Bitte Formular ausfüllen. Danach senden wir den Freigabelink per E-Mail.';
                    $defaultFormData['request_fields'] = [];
                    $defaultFormData['categorized_groups'] = [];
                    $defaultFormData['manual_files'] = [];
                    $defaultFormData['manual_groups'] = [];
                } else {
                    $token = BoardShareService::createShare(
                        $shareMode,
                        $shareMode === 'article' ? $articleId : 0,
                        $sourceMode,
                        $mediaCategoryId,
                        $validManualFiles,
                        is_array($manualGroups) ? $manualGroups : [],
                        $categorizedGroups,
                        $requestEnabled,
                        max(1, $requestValidDays),
                        $requestFields,
                        $requestIntroText !== '' ? $requestIntroText : null,
                        $title !== '' ? $title : null,
                        $description !== '' ? $description : null,
                        $allowZip,
                        $password !== '' ? $password : null,
                        $expiresAt,
                        $maxDownloads > 0 ? $maxDownloads : null,
                        $fileDownloadLimits,
                        $user->getLogin()
                    );

                    $relativeUrl = BoardShareService::buildPublicShareUrl($shareMode, $shareMode === 'article' ? $articleId : 0, $token);
                    $createdShareUrl = rtrim((string) rex::getServer(), '/') . $relativeUrl;
                    echo rex_view::success('Dateifreigabe erstellt. Link unten kopieren oder teilen.');
                }
            }
        }
    }
}

$categorySelectHtml = '<select id="media_category_id" name="media_category_id" class="form-control selectpicker" data-live-search="true" onchange="this.form.submit();">';
$categorySelectHtml .= $buildMediaCategoryOptionsSelected(rex_media_category::getRootCategories(false), $selectedCategoryId);
$categorySelectHtml .= '</select>';

$mediaRows = $selectedCategoryId > 0 ? BoardShareService::getMediaForCategory($selectedCategoryId) : [];
$shares = BoardShareService::getShares();

$downloadEventsByShare = [];
$topFileByShare = [];
$topCategoryByShare = [];

$downloadCountRows = rex_sql::factory()->getArray(
    'SELECT share_id, COUNT(*) AS cnt FROM ' . rex::getTable('klxm_restricted_file_share_download') . ' GROUP BY share_id'
);
foreach ($downloadCountRows as $row) {
    $downloadEventsByShare[(int) $row['share_id']] = (int) ($row['cnt'] ?? 0);
}

$topFileRows = rex_sql::factory()->getArray(
    'SELECT share_id, filename, COUNT(*) AS cnt '
    . 'FROM ' . rex::getTable('klxm_restricted_file_share_download') . ' '
    . 'GROUP BY share_id, filename '
    . 'ORDER BY share_id ASC, cnt DESC, filename ASC'
);
foreach ($topFileRows as $row) {
    $shareId = (int) ($row['share_id'] ?? 0);
    if ($shareId <= 0 || isset($topFileByShare[$shareId])) {
        continue;
    }

    $topFileByShare[$shareId] = [
        'filename' => (string) ($row['filename'] ?? ''),
        'downloads' => (int) ($row['cnt'] ?? 0),
    ];
}

$topCategoryRows = rex_sql::factory()->getArray(
    'SELECT dl.share_id, m.category_id, COUNT(*) AS cnt '
    . 'FROM ' . rex::getTable('klxm_restricted_file_share_download') . ' dl '
    . 'LEFT JOIN ' . rex::getTable('media') . ' m ON m.filename = dl.filename '
    . 'GROUP BY dl.share_id, m.category_id '
    . 'ORDER BY dl.share_id ASC, cnt DESC, m.category_id ASC'
);
foreach ($topCategoryRows as $row) {
    $shareId = (int) ($row['share_id'] ?? 0);
    if ($shareId <= 0 || isset($topCategoryByShare[$shareId])) {
        continue;
    }

    $topCategoryByShare[$shareId] = [
        'category_id' => (int) ($row['category_id'] ?? 0),
        'downloads' => (int) ($row['cnt'] ?? 0),
    ];
}

$showForm = in_array($func, ['edit_share', 'new_share'], true) || rex_request('create_file_share', 'int', 0) === 1;
$newShareUrl = rex_url::backendController([
    'page' => 'mediapool/klxm_restricted_file_share',
    'func' => 'new_share',
    'media_category_id' => $selectedCategoryId,
    'source_mode' => $sourceMode,
    'share_mode' => $shareMode,
]);
$listUrl = rex_url::backendController([
    'page' => 'mediapool/klxm_restricted_file_share',
]);

if ($showForm) {
echo '<div class="panel panel-primary">';
echo '<div class="panel-heading"><h3 class="panel-title">Dateiablage teilen</h3></div>';
echo '<div class="panel-body">';
echo '<p>Modus "Seitengebunden": Ausgabe auf REDAXO-Seite. Modus "Direkt (klassisch)": Freigabe ohne Artikelauswahl nur über Link.</p>';
echo '<p><a class="btn btn-default" href="' . $listUrl . '">Zur Liste</a></p>';

echo '<form method="get" class="form-inline" style="margin-bottom:15px;">';
echo '<input type="hidden" name="page" value="mediapool/klxm_restricted_file_share">';
if ($func === 'edit_share' && $editShareId > 0) {
    echo '<input type="hidden" name="func" value="edit_share">';
    echo '<input type="hidden" name="share_id" value="' . (int) $editShareId . '">';
} elseif ($func === 'new_share') {
    echo '<input type="hidden" name="func" value="new_share">';
}
echo '<div class="form-group" style="min-width:360px; margin-right:12px;">';
echo '<label for="media_category_id" style="margin-right:8px;">Medienpool-Kategorie</label>';
echo $categorySelectHtml;
echo '</div>';
echo '<div class="form-group" style="margin-right:12px;">';
echo '<label for="share_mode" style="margin-right:8px;">Freigabe-Modus</label>';
echo '<select id="share_mode" name="share_mode" class="form-control" onchange="this.form.submit();">';
echo '<option value="article"' . ($shareMode === 'article' ? ' selected' : '') . '>Seitengebunden</option>';
echo '<option value="direct"' . ($shareMode === 'direct' ? ' selected' : '') . '>Direkt (klassisch)</option>';
echo '</select>';
echo '</div>';
echo '<div class="form-group">';
echo '<label for="source_mode" style="margin-right:8px;">Quellenmodus</label>';
echo '<select id="source_mode" name="source_mode" class="form-control" onchange="this.form.submit();">';
echo '<option value="category"' . ($sourceMode === 'category' ? ' selected' : '') . '>Komplette Kategorie</option>';
echo '<option value="manual"' . ($sourceMode === 'manual' ? ' selected' : '') . '>Manuelle Gruppierung</option>';
echo '<option value="categorized"' . ($sourceMode === 'categorized' ? ' selected' : '') . '>Kategorisierter Share (Repeater)</option>';
echo '</select>';
echo '</div>';
echo '</form>';

if ($createdShareUrl !== '') {
    echo '<div class="alert alert-success">';
    echo '<strong>Freigabelink:</strong><br>';
    echo '<input class="form-control" type="text" readonly value="' . htmlspecialchars($createdShareUrl) . '">';
    echo '</div>';
}

if ($selectedCategoryId <= 0) {
    echo rex_view::info('Bitte zuerst eine Medienpool-Kategorie waehlen.');
} elseif ($mediaRows === []) {
    echo rex_view::info('In dieser Kategorie sind keine Medien vorhanden.');
} else {
    $isEditMode = $editShareId > 0 && is_array($editShare);
    $expiresInputValue = '';
    if ((string) ($defaultFormData['expires_at'] ?? '') !== '') {
        $expiresTs = strtotime((string) $defaultFormData['expires_at']);
        if ($expiresTs !== false) {
            $expiresInputValue = date('Y-m-d\\TH:i', $expiresTs);
        }
    }

    echo '<form method="post">';
    echo '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($csrf->getValue()) . '">';
    echo '<input type="hidden" name="create_file_share" value="1">';
    echo '<input type="hidden" name="edit_share_id" value="' . ($isEditMode ? (int) $editShareId : 0) . '">';

    if ($isEditMode) {
        $cancelUrl = rex_url::backendController([
            'page' => 'mediapool/klxm_restricted_file_share',
            'media_category_id' => $selectedCategoryId,
        ]);
        echo '<div class="alert alert-info">Bearbeitung aktiv für Share #' . (int) $editShareId . '. <a href="' . $cancelUrl . '">Bearbeitung abbrechen</a></div>';
    }

    echo '<div class="form-group" data-share-mode-article="1"' . ($shareMode === 'article' ? '' : ' style="display:none;"') . '>';
    echo '<label for="article_id">Ausgabeseite (REDAXO-Artikel)</label>';
    echo \rex_var_link::getWidget(1, 'article_id', $selectedArticleId);
    echo '<p class="help-block">Diese Seite legt der Redakteur normal an (Text, Hinweise etc.). Das Modul "KLXM Restricted Dateiablage" zeigt die Dateien an.</p>';
    echo '</div>';

    echo '<input type="hidden" name="share_mode" value="' . htmlspecialchars($shareMode) . '">';

    echo '<input type="hidden" name="source_mode" value="' . htmlspecialchars($sourceMode) . '">';
    echo '<input type="hidden" name="media_category_id" value="' . (int) $selectedCategoryId . '">';

    echo '<div class="form-group">';
    echo '<label for="share_title">Titel (optional)</label>';
    echo '<input id="share_title" class="form-control" type="text" name="share_title" maxlength="191" value="' . htmlspecialchars((string) $defaultFormData['title']) . '">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label for="share_description">Beschreibung / Intro (optional)</label>';
    echo '<textarea id="share_description" class="form-control" name="share_description" rows="3">' . htmlspecialchars((string) $defaultFormData['description']) . '</textarea>';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label for="expires_at">Ablauf (optional)</label>';
    echo '<input id="expires_at" class="form-control" type="datetime-local" name="expires_at" value="' . htmlspecialchars($expiresInputValue) . '">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label for="share_password">Passwort (optional)</label>';
    echo '<input id="share_password" class="form-control" type="text" name="share_password">';
    if ($isEditMode) {
        echo '<div class="checkbox" style="margin-top:8px;"><label><input type="checkbox" name="clear_password" value="1"> Vorhandenes Passwort entfernen</label></div>';
        echo '<p class="help-block">Leer lassen = vorhandenes Passwort bleibt unverändert.</p>';
    }
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label for="max_downloads">Maximale Downloads (optional)</label>';
    echo '<input id="max_downloads" class="form-control" type="number" min="0" step="1" name="max_downloads" placeholder="0 = unbegrenzt" value="' . htmlspecialchars((string) $defaultFormData['max_downloads']) . '">';
    echo '</div>';

    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><strong>Datei-Kontingente pro Datei (optional)</strong></div>';
    echo '<div class="panel-body">';
    echo '<p class="help-block">Zeilenweise via Mediapool-Picker wählen und Anzahl setzen. Ist das Kontingent erreicht, wird die Datei im Frontend nicht mehr zum Download angeboten.</p>';

    $limitRows = is_array($defaultFormData['file_download_limit_rows']) ? $defaultFormData['file_download_limit_rows'] : [];

    echo '<div id="klxm-file-limit-rows">';

    foreach ($limitRows as $rowIndex => $limitRow) {
        $medialistValue = is_array($limitRow) ? (string) ($limitRow['medialist'] ?? '') : '';
        $maxValue = is_array($limitRow) ? (string) ($limitRow['max'] ?? '') : '';
        $widgetId = (string) (97000 + (int) $rowIndex);

        echo '<div class="row klxm-file-limit-row" data-row-index="' . (int) $rowIndex . '" style="margin-bottom:8px;">';
        echo '<div class="col-sm-9">' . \rex_var_medialist::getWidget(
            $widgetId,
            'file_limit_medialist[' . $rowIndex . ']',
            $medialistValue,
            ['category' => $selectedCategoryId]
        ) . '</div>';
        echo '<div class="col-sm-2"><input class="form-control" type="number" min="1" step="1" name="file_limit_value[' . $rowIndex . ']" placeholder="Max" value="' . htmlspecialchars($maxValue) . '"></div>';
        echo '<div class="col-sm-1"><button type="button" class="btn btn-default form-control klxm-file-limit-remove">-</button></div>';
        echo '</div>';
    }

    echo '</div>';
    echo '<button type="button" class="btn btn-default" id="klxm-file-limit-add">Zeile hinzufügen</button>';

    echo '<template id="klxm-file-limit-template">';
    echo '<div class="row klxm-file-limit-row" data-row-index="__INDEX__" style="margin-bottom:8px;">';
    echo '<div class="col-sm-9">' . \rex_var_medialist::getWidget(
        '97000__INDEX__',
        'file_limit_medialist[__INDEX__]',
        '',
        ['category' => $selectedCategoryId]
    ) . '</div>';
    echo '<div class="col-sm-2"><input class="form-control" type="number" min="1" step="1" name="file_limit_value[__INDEX__]" placeholder="Max"></div>';
    echo '<div class="col-sm-1"><button type="button" class="btn btn-default form-control klxm-file-limit-remove">-</button></div>';
    echo '</div>';
    echo '</template>';

    echo '<p class="help-block">Hinweis: Wenn im Picker mehrere Dateien gewählt sind, gilt der Max-Wert für alle ausgewählten Dateien dieser Zeile.</p>';
    echo '</div>';
    echo '</div>';

    echo '<div class="checkbox">';
    echo '<label><input type="checkbox" name="allow_zip" value="1"' . ((bool) $defaultFormData['allow_zip'] ? ' checked' : '') . '> ZIP-Downloads erlauben (einzeln/ausgewaehlt/alle)</label>';
    echo '</div>';

    echo '<hr>';
    echo '<h4>Anfrageformular fuer externe Besucher</h4>';
    if ($shareMode === 'direct') {
        echo '<div class="alert alert-warning">Hinweis: Bei <strong>Direkt (klassisch)</strong> wird kein Anfrageformular abgefragt. Dieser Modus ist fuer direkten Linkzugriff gedacht.</div>';
    }
    echo '<div class="checkbox">';
    echo '<label><input type="checkbox" name="request_enabled" value="1"' . ((bool) $defaultFormData['request_enabled'] ? ' checked' : '') . '> Anfrageformular aktivieren</label>';
    echo '</div>';

    echo '<div class="form-group" style="max-width:320px;">';
    echo '<label for="request_valid_days">Freigabelink gueltig (Tage)</label>';
    echo '<input id="request_valid_days" class="form-control" type="number" min="1" max="60" name="request_valid_days" value="' . (int) $defaultFormData['request_valid_days'] . '">';
    echo '<p class="help-block">Nach dem Formularversand wird ein persoenlicher Link per E-Mail verschickt, gueltig fuer diese Anzahl Tage.</p>';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label for="request_intro_text">Hinweistext über dem Anfrageformular</label>';
    echo '<textarea id="request_intro_text" class="form-control" name="request_intro_text" rows="2">' . htmlspecialchars((string) $defaultFormData['request_intro_text']) . '</textarea>';
    echo '<p class="help-block">Kann pro Share überschrieben werden. Umlaute sind erlaubt.</p>';
    echo '</div>';

    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><strong>Formularfelder (zusetzlich zu E-Mail)</strong></div>';
    echo '<div class="panel-body">';
    echo '<p class="help-block">Typen: Text, Freitext, Checkbox, Select, Radio, Rating. Bei Select/Radio Optionen mit | trennen, z. B. Einkauf|Bauleitung|Geschaeftsfuehrung. Rating nutzt standardmäßig 1|2|3|4|5 Sterne.</p>';
    echo '<div id="klxm-request-fields">';

    $requestFieldRows = is_array($defaultFormData['request_fields']) ? $defaultFormData['request_fields'] : [];
    if ($requestFieldRows === []) {
        $requestFieldRows = [
            ['key' => '', 'label' => '', 'type' => 'text', 'required' => '0', 'options' => ''],
            ['key' => '', 'label' => '', 'type' => 'text', 'required' => '0', 'options' => ''],
            ['key' => '', 'label' => '', 'type' => 'text', 'required' => '0', 'options' => ''],
        ];
    }

    foreach ($requestFieldRows as $requestFieldRow) {
        $rowKey = htmlspecialchars((string) ($requestFieldRow['key'] ?? ''));
        $rowLabel = htmlspecialchars((string) ($requestFieldRow['label'] ?? ''));
        $rowType = (string) ($requestFieldRow['type'] ?? 'text');
        $rowRequired = (string) ($requestFieldRow['required'] ?? '0');
        $rowOptions = htmlspecialchars((string) ($requestFieldRow['options'] ?? ''));

        echo '<div class="row klxm-request-row" style="margin-bottom:8px;">';
        echo '<div class="col-sm-2"><input class="form-control" type="text" name="request_field_key[]" placeholder="key z.B. firma" value="' . $rowKey . '"></div>';
        echo '<div class="col-sm-3"><input class="form-control" type="text" name="request_field_label[]" placeholder="Label z.B. Firma" value="' . $rowLabel . '"></div>';
        echo '<div class="col-sm-2">';
        echo '<select class="form-control" name="request_field_type[]">';
        echo '<option value="text"' . ($rowType === 'text' ? ' selected' : '') . '>Text</option>';
        echo '<option value="textarea"' . ($rowType === 'textarea' ? ' selected' : '') . '>Freitext</option>';
        echo '<option value="checkbox"' . ($rowType === 'checkbox' ? ' selected' : '') . '>Checkbox</option>';
        echo '<option value="select"' . ($rowType === 'select' ? ' selected' : '') . '>Select</option>';
        echo '<option value="radio"' . ($rowType === 'radio' ? ' selected' : '') . '>Radio</option>';
        echo '<option value="rating"' . ($rowType === 'rating' ? ' selected' : '') . '>Rating (Sterne)</option>';
        echo '</select>';
        echo '</div>';
        echo '<div class="col-sm-3"><input class="form-control" type="text" name="request_field_options[]" placeholder="Optionen fuer Select" value="' . $rowOptions . '"></div>';
        echo '<div class="col-sm-1">';
        echo '<select class="form-control" name="request_field_required[]">';
        echo '<option value="0"' . ($rowRequired === '0' ? ' selected' : '') . '>Optional</option>';
        echo '<option value="1"' . ($rowRequired === '1' ? ' selected' : '') . '>Pflicht</option>';
        echo '</select>';
        echo '</div>';
        echo '<div class="col-sm-1"><button type="button" class="btn btn-default klxm-request-remove">-</button></div>';
        echo '</div>';
    }

    echo '</div>';
    echo '<button type="button" class="btn btn-default" id="klxm-request-add-row">Feld hinzufuegen</button>';
    echo '</div>';
    echo '</div>';

    echo '<hr>';

    if ($sourceMode === 'manual') {
        echo '<h4>Dateien manuell gruppieren</h4>';
        echo '<p class="help-block">Datei ankreuzen und optional einen Rubriknamen vergeben. Leer = "Allgemein".</p>';
        echo '<div style="max-height:350px; overflow:auto; border:1px solid #ddd; padding:10px;">';
        $selectedManualFiles = is_array($defaultFormData['manual_files']) ? $defaultFormData['manual_files'] : [];
        $selectedManualGroups = is_array($defaultFormData['manual_groups']) ? $defaultFormData['manual_groups'] : [];
        foreach ($mediaRows as $media) {
            $filename = $media['filename'];
            $displayName = $media['title'] !== '' ? $media['title'] : $filename;
            $isChecked = in_array($filename, $selectedManualFiles, true);
            $groupValue = (string) ($selectedManualGroups[$filename] ?? '');
            echo '<div class="row" style="margin-bottom:8px;">';
            echo '<div class="col-sm-6">';
            echo '<label><input type="checkbox" name="manual_files[]" value="' . htmlspecialchars($filename) . '"' . ($isChecked ? ' checked' : '') . '> ' . htmlspecialchars($displayName) . '</label>';
            echo '<div><small class="text-muted">' . htmlspecialchars($filename) . '</small></div>';
            echo '</div>';
            echo '<div class="col-sm-6">';
            echo '<input class="form-control" type="text" name="manual_groups[' . htmlspecialchars($filename) . ']" placeholder="Rubrik (z.B. Angebote)" value="' . htmlspecialchars($groupValue) . '">';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    } elseif ($sourceMode === 'categorized') {
        echo '<h4>Kategorisierter Share</h4>';
        echo '<p class="help-block">Jeder Repeater-Block ist eine Kategorie. Quelle pro Block: Medialist (inkl. Reihenfolge) oder komplette Medienpool-Kategorie. Reihenfolge aus dem Medialist wird übernommen.</p>';

        $categorizedGroupsData = is_array($defaultFormData['categorized_groups']) ? $defaultFormData['categorized_groups'] : [];
        if ($categorizedGroupsData === []) {
            $categorizedGroupsData = [[
                'name' => '',
                'source' => 'manual',
                'media_category_id' => 0,
                'files' => [],
            ]];
        }

        echo '<div id="klxm-categorized-groups">';

        foreach ($categorizedGroupsData as $groupIndex => $groupData) {
            if (!is_array($groupData)) {
                continue;
            }

            $groupName = htmlspecialchars(trim((string) ($groupData['name'] ?? '')));
            $groupSource = (string) ($groupData['source'] ?? 'manual');
            $groupCategoryId = (int) ($groupData['media_category_id'] ?? 0);
            $groupFiles = [];
            if (isset($groupData['files']) && is_array($groupData['files'])) {
                foreach ($groupData['files'] as $fileName) {
                    if (is_string($fileName) && $fileName !== '') {
                        $groupFiles[] = $fileName;
                    }
                }
            }

            echo '<div class="panel panel-default klxm-categorized-group" data-group-index="' . (int) $groupIndex . '">';
            echo '<div class="panel-body">';
            echo '<div class="row" style="margin-bottom:8px;">';
            echo '<div class="col-sm-4"><label>Kategorie</label><input class="form-control" type="text" name="categorized_group_name[' . (int) $groupIndex . ']" placeholder="z.B. Angebote" value="' . $groupName . '"></div>';
            echo '<div class="col-sm-3"><label>Quelle</label><select class="form-control klxm-categorized-source" name="categorized_group_source[' . (int) $groupIndex . ']"><option value="manual"' . ($groupSource === 'manual' ? ' selected' : '') . '>Medien auswählen (Medialist)</option><option value="media_category"' . ($groupSource === 'media_category' ? ' selected' : '') . '>Medienpool-Kategorie</option></select></div>';

            $selectedCategoryOptions = $buildMediaCategoryOptionsSelected(rex_media_category::getRootCategories(false), $groupCategoryId);
            echo '<div class="col-sm-4 klxm-categorized-category-block"' . ($groupSource === 'media_category' ? '' : ' style="display:none;"') . '><label>Medienpool-Kategorie</label><select class="form-control selectpicker" data-live-search="true" data-size="8" name="categorized_group_media_category_id[' . (int) $groupIndex . ']"><option value="0">Bitte wählen</option>' . $selectedCategoryOptions . '</select></div>';
            echo '<div class="col-sm-1"><label>&nbsp;</label><button type="button" class="btn btn-default form-control klxm-categorized-remove">-</button></div>';
            echo '</div>';
            echo '<div class="klxm-categorized-manual-block"' . ($groupSource === 'media_category' ? ' style="display:none;"' : '') . '>';
            echo '<label>Medien auswählen (inkl. Reihenfolge)</label>';
            $groupMedialistValue = implode(',', $groupFiles);
            echo \rex_var_medialist::getWidget(
                'klxm_categorized_manual_' . (int) $groupIndex,
                'categorized_group_manual_medialist[' . (int) $groupIndex . ']',
                $groupMedialistValue,
                ['category' => $selectedCategoryId]
            );
            echo '<p class="help-block">Mit den Pfeilen im Medialist die Reihenfolge festlegen. Frontend-Besucher können weiterhin A-Z/Z-A sortieren.</p>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
        echo '<button type="button" class="btn btn-default" id="klxm-categorized-add">Kategorie hinzufügen</button>';
        echo '<template id="klxm-categorized-template">';
        echo '<div class="panel panel-default klxm-categorized-group" data-group-index="__INDEX__">';
        echo '<div class="panel-body">';
        echo '<div class="row" style="margin-bottom:8px;">';
        echo '<div class="col-sm-4"><label>Kategorie</label><input class="form-control" type="text" name="categorized_group_name[__INDEX__]" placeholder="z.B. Angebote"></div>';
        echo '<div class="col-sm-3"><label>Quelle</label><select class="form-control klxm-categorized-source" name="categorized_group_source[__INDEX__]"><option value="manual">Medien auswählen (Medialist)</option><option value="media_category">Medienpool-Kategorie</option></select></div>';
        echo '<div class="col-sm-4 klxm-categorized-category-block" style="display:none;"><label>Medienpool-Kategorie</label><select class="form-control selectpicker" data-live-search="true" data-size="8" name="categorized_group_media_category_id[__INDEX__]"><option value="0">Bitte wählen</option>' . $mediaCategoryOptionsHtml . '</select></div>';
        echo '<div class="col-sm-1"><label>&nbsp;</label><button type="button" class="btn btn-default form-control klxm-categorized-remove">-</button></div>';
        echo '</div>';
        echo '<div class="klxm-categorized-manual-block">';
        echo '<label>Medien auswählen (inkl. Reihenfolge)</label>';
        echo \rex_var_medialist::getWidget(
            'klxm_categorized_manual___INDEX__',
            'categorized_group_manual_medialist[__INDEX__]',
            '',
            ['category' => $selectedCategoryId]
        );
        echo '<p class="help-block">Mit den Pfeilen im Medialist die Reihenfolge festlegen. Frontend-Besucher können weiterhin A-Z/Z-A sortieren.</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</template>';
    } else {
        echo rex_view::info('Es werden automatisch alle Dateien aus der gewaehlten Medienpool-Kategorie gelistet.');
    }

    echo '<div style="margin-top:15px;">';
    echo '<button type="submit" class="btn btn-primary">' . ($isEditMode ? 'Dateifreigabe aktualisieren' : 'Dateifreigabe erstellen') . '</button>';
    echo '</div>';
    echo '</form>';
}

echo '</div>';
echo '</div>';
}

echo '<div class="panel panel-default">';
echo '<div class="panel-heading clearfix">';
echo '<h3 class="panel-title pull-left" style="margin-top:8px;">Bestehende Dateifreigaben</h3>';
echo '<div class="pull-right"><a class="btn btn-primary btn-sm" href="' . $newShareUrl . '">Neue Freigabe</a></div>';
echo '</div>';
echo '<div class="panel-body">';

if ($shares === []) {
    echo rex_view::info('Noch keine Dateifreigaben vorhanden.');
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-striped table-hover table-condensed">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>Freigabe</th><th>Gültigkeit</th><th>Downloads</th><th>Statistik</th><th>Link</th><th>Aktion</th>';
    echo '</tr></thead><tbody>';
    $statsModalsHtml = '';

    foreach ($shares as $share) {
        $articleId = (int) ($share['article_id'] ?? 0);
        $article = $articleId > 0 ? rex_article::get($articleId) : null;
        $articleLabel = $article ? $article->getName() . ' [' . $articleId . ']' : (string) $articleId;

        $deleteUrl = rex_url::backendController(array_merge([
            'page' => 'mediapool/klxm_restricted_file_share',
            'func' => 'delete_share',
            'share_id' => (int) $share['id'],
            'media_category_id' => $selectedCategoryId,
            'source_mode' => $sourceMode,
        ], $csrf->getUrlParams()));

        $editUrl = rex_url::backendController([
            'page' => 'mediapool/klxm_restricted_file_share',
            'func' => 'edit_share',
            'share_id' => (int) $share['id'],
            'media_category_id' => (int) ($share['media_category_id'] ?? 0),
            'source_mode' => (string) ($share['source_mode'] ?? 'category'),
            'share_mode' => (string) ($share['share_mode'] ?? 'article'),
        ]);

        $tokenPlain = trim((string) ($share['token_plain'] ?? ''));
        $shareUrl = '';
        $shareModeRow = (string) ($share['share_mode'] ?? 'article');
        $shareIdRow = (int) ($share['id'] ?? 0);
        if ($tokenPlain !== '') {
            $relative = BoardShareService::buildPublicShareUrl($shareModeRow, $articleId, $tokenPlain);
            $shareUrl = rtrim((string) rex::getServer(), '/') . $relative;
        }

        $statsUrl = rex_url::backendController([
            'page' => 'klxm_restricted/share_requests',
            'share_id' => $shareIdRow,
        ]);

        $eventDownloads = (int) ($downloadEventsByShare[$shareIdRow] ?? 0);
        $topFile = $topFileByShare[$shareIdRow] ?? null;
        $topCategory = $topCategoryByShare[$shareIdRow] ?? null;

        $topFileText = '-';
        if (is_array($topFile) && (string) ($topFile['filename'] ?? '') !== '') {
            $topFileText = (string) $topFile['filename'] . ' (' . (int) ($topFile['downloads'] ?? 0) . ')';
        }

        $topCategoryText = '-';
        if (is_array($topCategory)) {
            $categoryId = (int) ($topCategory['category_id'] ?? 0);
            if ($categoryId > 0) {
                $category = rex_media_category::get($categoryId);
                $categoryName = $category ? $category->getName() : ('Kategorie #' . $categoryId);
                $topCategoryText = $categoryName . ' (' . (int) ($topCategory['downloads'] ?? 0) . ')';
            }
        }

        $statsModalId = 'klxm-share-stats-modal-' . $shareIdRow;
        $downloadText = (int) ($share['download_count'] ?? 0)
            . (((($share['max_downloads'] ?? null) !== null && (string) ($share['max_downloads'] ?? '') !== '') ? ' / ' . (int) $share['max_downloads'] : ''));
        $statsSummary = 'Events: ' . $eventDownloads
            . ' | Top-Datei: ' . $topFileText
            . ' | Top-Kategorie: ' . $topCategoryText;

        echo '<tr>';
        echo '<td>' . (int) $share['id'] . '</td>';
        echo '<td>';
        echo '<strong>' . htmlspecialchars((string) ($share['title'] ?? '')) . '</strong><br>';
        echo '<small class="text-muted">'
            . htmlspecialchars($shareModeRow === 'direct' ? 'Direkt (klassisch)' : 'Seitengebunden')
            . ' | '
            . htmlspecialchars((string) ($share['source_mode'] ?? ''))
            . ' | Kat. ' . (int) ($share['media_category_id'] ?? 0)
            . ' | ' . htmlspecialchars($articleLabel)
            . '</small>';
        echo '</td>';
        echo '<td>';
        echo '<small>';
        echo 'Ablauf: ' . htmlspecialchars((string) ($share['expires_at'] ?? '-')) . '<br>';
        echo 'Anfrage: ' . ((int) ($share['request_enabled'] ?? 0) === 1 ? 'ja (' . (int) ($share['request_valid_days'] ?? 0) . ' Tage)' : 'nein');
        echo '</small>';
        echo '</td>';
        echo '<td>' . $downloadText . '<br><small class="text-muted">ZIP ' . ((int) ($share['allow_zip'] ?? 0) === 1 ? 'ja' : 'nein') . '</small></td>';
        echo '<td>';
        echo '<button type="button" class="btn btn-xs btn-default" data-toggle="modal" data-target="#' . $statsModalId . '" title="' . htmlspecialchars($statsSummary) . '">Stats</button> ';
        echo '<a class="btn btn-xs btn-link klxm-stats-detail-link" data-open-parent="1" target="_blank" rel="noopener noreferrer" href="' . $statsUrl . '">Detail</a>';
        echo '</td>';

        if ($shareUrl !== '') {
            echo '<td>';
            echo '<button type="button" class="btn btn-xs btn-default klxm-copy-share-link" data-link="' . htmlspecialchars($shareUrl) . '" title="Freigabelink kopieren">Link kopieren</button>';
            echo '</td>';
        } else {
            echo '<td><span class="text-muted">Kein Link verfuegbar</span></td>';
        }

        echo '<td><a class="btn btn-xs btn-primary" href="' . $editUrl . '">Bearbeiten</a> <a class="btn btn-xs btn-danger" href="' . $deleteUrl . '" onclick="return confirm(\'Freigabe wirklich loeschen?\');">Löschen</a></td>';
        echo '</tr>';

        $statsModalsHtml .= '<div class="modal fade" id="' . $statsModalId . '" tabindex="-1" role="dialog" aria-labelledby="' . $statsModalId . '-title">'
            . '<div class="modal-dialog" role="document">'
            . '<div class="modal-content">'
            . '<div class="modal-header">'
            . '<button type="button" class="close" data-dismiss="modal" aria-label="Schließen"><span aria-hidden="true">&times;</span></button>'
            . '<h4 class="modal-title" id="' . $statsModalId . '-title">Statistik Share #' . $shareIdRow . '</h4>'
            . '</div>'
            . '<div class="modal-body">'
            . '<p><strong>Titel:</strong> ' . htmlspecialchars((string) ($share['title'] ?? '')) . '</p>'
            . '<ul class="list-unstyled">'
            . '<li><strong>Events:</strong> ' . $eventDownloads . '</li>'
            . '<li><strong>Top-Datei:</strong> ' . htmlspecialchars($topFileText) . '</li>'
            . '<li><strong>Top-Kategorie:</strong> ' . htmlspecialchars($topCategoryText) . '</li>'
            . '<li><strong>Erstellt von:</strong> ' . htmlspecialchars((string) ($share['created_by'] ?? '')) . '</li>'
            . '</ul>'
            . '</div>'
            . '<div class="modal-footer">'
            . '<a class="btn btn-primary" href="' . $statsUrl . '">Zur Detailstatistik</a>'
            . '<button type="button" class="btn btn-default" data-dismiss="modal">Schließen</button>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    echo '</tbody></table>';
    echo $statsModalsHtml;
    echo '</div>';
}

echo '</div>';
echo '</div>';
