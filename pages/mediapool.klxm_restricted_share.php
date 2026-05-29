<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use KLXM\Restricted\Media\ShareService;
use rex;
use rex_csrf_token;
use rex_media_category_select;
use rex_request;
use rex_url;
use rex_view;

$user = rex::requireUser();
if (!$user->isAdmin() && !$user->hasPerm('klxm_restricted[share]')) {
    echo rex_view::error('Keine Berechtigung fuer Medienfreigaben.');
    return;
}

$csrf = rex_csrf_token::factory('klxm_restricted_media_share');
$selectedCategoryId = rex_request('rex_file_category', 'int', 0);
$func = rex_request('func', 'string', '');

$createdShareUrl = '';

if ($func === 'delete_share') {
    if (!$csrf->isValid()) {
        echo rex_view::error('Aktion abgelehnt (ungueltiger CSRF-Token).');
    } else {
        $shareId = rex_request('share_id', 'int', 0);
        if ($shareId > 0) {
            ShareService::deleteShare($shareId);
            echo rex_view::success('Freigabe wurde entfernt.');
        }
    }
}

if (rex_request('create_share', 'int', 0) === 1) {
    if (!$csrf->isValid()) {
        echo rex_view::error('Aktion abgelehnt (ungueltiger CSRF-Token).');
    } else {
        $categoryId = rex_request('rex_file_category', 'int', 0);
        $selectedFiles = rex_request('media_files', 'array', []);
        $title = trim(rex_request('share_title', 'string', ''));
        $password = rex_request('share_password', 'string', '');
        $expiresRaw = trim(rex_request('expires_at', 'string', ''));
        $allowZip = rex_request('allow_zip', 'int', 0) === 1;
        $maxDownloads = rex_request('max_downloads', 'int', 0);
        $selectAllFiles = rex_request('select_all_files', 'int', 0) === 1;

        $availableMedia = ShareService::getMediaForCategory($categoryId);
        $allowedFilenames = [];
        foreach ($availableMedia as $media) {
            $allowedFilenames[] = $media['filename'];
        }

        $validSelection = [];
        if ($selectAllFiles) {
            $validSelection = $allowedFilenames;
        } else {
            foreach ($selectedFiles as $filename) {
                if (!is_string($filename) || $filename === '') {
                    continue;
                }
                if (in_array($filename, $allowedFilenames, true)) {
                    $validSelection[] = $filename;
                }
            }
        }

        if ($categoryId <= 0) {
            echo rex_view::error('Bitte eine Medienpool-Kategorie auswaehlen.');
        } elseif ($validSelection === []) {
            echo rex_view::error('Bitte mindestens eine Datei auswaehlen.');
        } else {
            $expiresAt = null;
            if ($expiresRaw !== '') {
                $expiresTimestamp = strtotime($expiresRaw);
                if ($expiresTimestamp !== false) {
                    $expiresAt = date('Y-m-d H:i:s', $expiresTimestamp);
                }
            }

            $maxDownloadsValue = $maxDownloads > 0 ? $maxDownloads : null;

            $token = ShareService::createShare(
                $categoryId,
                $validSelection,
                $title !== '' ? $title : null,
                $allowZip,
                $password !== '' ? $password : null,
                $expiresAt,
                $maxDownloadsValue,
                $user->getLogin()
            );

            $frontendBase = rtrim((string) rex::getServer(), '/');
            $createdShareUrl = $frontendBase . '/index.php?klxm_share=' . rawurlencode($token);
            echo rex_view::success('Freigabe erstellt. Link unten kopieren.');
        }
    }
}

$categorySelect = new rex_media_category_select(false);
$categorySelect->setName('rex_file_category');
$categorySelect->setId('rex_file_category');
$categorySelect->setSelected($selectedCategoryId);
$categorySelect->setSize(1);
$categorySelect->setAttribute('class', 'form-control selectpicker');
$categorySelect->setAttribute('data-live-search', 'true');
$categorySelect->setAttribute('onchange', 'this.form.submit();');

$mediaRows = $selectedCategoryId > 0 ? ShareService::getMediaForCategory($selectedCategoryId) : [];
$shares = ShareService::getShares();
$frontendBase = rtrim((string) rex::getServer(), '/');

echo '<div class="panel panel-primary">';
echo '<div class="panel-heading"><h3 class="panel-title">Medien teilen</h3></div>';
echo '<div class="panel-body">';
echo '<p>Erstellt zeitlich begrenzte Freigabelinks fuer Medien aus einer Medienpool-Kategorie.</p>';
echo '<form method="get" class="form-inline" style="margin-bottom:15px;">';
echo '<input type="hidden" name="page" value="mediapool/klxm_restricted_share">';
echo '<div class="form-group" style="min-width:360px;">';
echo '<label for="rex_file_category" style="margin-right:8px;">Kategorie</label>';
echo $categorySelect->get();
echo '</div>';
echo '</form>';

if ($createdShareUrl !== '') {
    echo '<div class="alert alert-success">';
    echo '<strong>Freigabelink:</strong><br>';
    echo '<input class="form-control" type="text" readonly value="' . htmlspecialchars($createdShareUrl) . '">';
    echo '</div>';
}

if ($selectedCategoryId <= 0) {
    echo rex_view::info('Bitte eine Kategorie auswaehlen.');
} elseif ($mediaRows === []) {
    echo rex_view::info('In dieser Kategorie sind keine Medien vorhanden.');
} else {
    echo '<form method="post">';
    echo '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($csrf->getValue()) . '">';
    echo '<input type="hidden" name="create_share" value="1">';
    echo '<input type="hidden" name="rex_file_category" value="' . $selectedCategoryId . '">';

    echo '<div class="form-group">';
    echo '<label for="share_title">Titel (optional)</label>';
    echo '<input id="share_title" class="form-control" type="text" name="share_title" maxlength="191">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label for="expires_at">Ablauf (optional)</label>';
    echo '<input id="expires_at" class="form-control" type="datetime-local" name="expires_at">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label for="share_password">Passwort (optional)</label>';
    echo '<input id="share_password" class="form-control" type="text" name="share_password">';
    echo '</div>';

    echo '<div class="form-group">';
    echo '<label for="max_downloads">Maximale Downloads (optional)</label>';
    echo '<input id="max_downloads" class="form-control" type="number" min="0" step="1" name="max_downloads" placeholder="0 = unbegrenzt">';
    echo '</div>';

    echo '<div class="checkbox">';
    echo '<label><input type="checkbox" name="allow_zip" value="1" checked> ZIP-Download erlauben</label>';
    echo '</div>';

    echo '<div class="checkbox">';
    echo '<label><input type="checkbox" name="select_all_files" value="1"> Alle Dateien der gewaehlten Kategorie freigeben</label>';
    echo '</div>';

    echo '<hr>';
    echo '<h4>Dateien aus Kategorie</h4>';
    echo '<div style="max-height:280px; overflow:auto; border:1px solid #ddd; padding:10px;">';

    foreach ($mediaRows as $media) {
        $filename = $media['filename'];
        $label = $media['title'] !== '' ? $media['title'] . ' (' . $filename . ')' : $filename;
        echo '<div class="checkbox">';
        echo '<label><input type="checkbox" name="media_files[]" value="' . htmlspecialchars($filename) . '"> ' . htmlspecialchars($label) . '</label>';
        echo '</div>';
    }

    echo '</div>';
    echo '<div style="margin-top:15px;">';
    echo '<button type="submit" class="btn btn-primary">Freigabe erstellen</button>';
    echo '</div>';
    echo '</form>';
}

echo '</div>';
echo '</div>';

echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">Bestehende Freigaben</h3></div>';
echo '<div class="panel-body">';

if ($shares === []) {
    echo rex_view::info('Noch keine Freigaben vorhanden.');
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-striped table-hover">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>Titel</th><th>Kategorie</th><th>Dateien</th><th>Ablauf</th><th>Downloads</th><th>ZIP</th><th>Erstellt von</th><th>Freigabelink</th><th></th>';
    echo '</tr></thead><tbody>';

    foreach ($shares as $share) {
        $files = json_decode((string) $share['media_files'], true);
        $fileCount = is_array($files) ? count($files) : 0;

        $deleteUrl = rex_url::backendController(array_merge([
            'page' => 'mediapool/klxm_restricted_share',
            'func' => 'delete_share',
            'share_id' => (int) $share['id'],
            'rex_file_category' => $selectedCategoryId,
        ], $csrf->getUrlParams()));

        echo '<tr>';
        echo '<td>' . (int) $share['id'] . '</td>';
        echo '<td>' . htmlspecialchars((string) $share['title']) . '</td>';
        echo '<td>' . (int) $share['category_id'] . '</td>';
        echo '<td>' . $fileCount . '</td>';
        echo '<td>' . htmlspecialchars((string) $share['expires_at']) . '</td>';
        echo '<td>' . (int) $share['download_count'] . (($share['max_downloads'] !== null && $share['max_downloads'] !== '') ? ' / ' . (int) $share['max_downloads'] : '') . '</td>';
        echo '<td>' . ((int) $share['allow_zip'] === 1 ? 'ja' : 'nein') . '</td>';
        echo '<td>' . htmlspecialchars((string) $share['created_by']) . '</td>';
        $tokenPlain = trim((string) ($share['token_plain'] ?? ''));
        if ($tokenPlain !== '') {
            $shareUrl = $frontendBase . '/index.php?klxm_share=' . rawurlencode($tokenPlain);
            $inputId = 'klxm-share-link-' . (int) $share['id'];
            echo '<td style="min-width:340px;">';
            echo '<div class="input-group">';
            echo '<input id="' . $inputId . '" class="form-control klxm-share-link-input" type="text" readonly value="' . htmlspecialchars($shareUrl) . '">';
            echo '<span class="input-group-btn">';
            echo '<button type="button" class="btn btn-default klxm-copy-share-link" data-target="' . $inputId . '">Kopieren</button>';
            echo '</span>';
            echo '</div>';
            echo '</td>';
        } else {
            echo '<td><span class="text-muted">Nicht verfuegbar (alter Eintrag)</span></td>';
        }
        echo '<td><a class="btn btn-xs btn-danger" href="' . $deleteUrl . '" onclick="return confirm(\'Freigabe wirklich loeschen?\');">Loeschen</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

echo '</div>';
echo '</div>';
