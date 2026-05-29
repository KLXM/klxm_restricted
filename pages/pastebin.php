<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use KLXM\Restricted\Frontend\PastebinService;
use rex;
use rex_csrf_token;
use rex_media_category_select;
use rex_request;
use rex_url;
use rex_view;

$user = rex::requireUser();
if (!$user->isAdmin() && !$user->hasPerm('klxm_restricted[pastebin]')) {
    echo rex_view::error('Keine Berechtigung fuer Pastebin.');
    return;
}

$csrf = rex_csrf_token::factory('klxm_restricted_pastebin');
$selectedCategoryId = rex_request('paste_media_category', 'int', 0);
$func = rex_request('func', 'string', '');
$createdUrl = '';

if ($func === 'delete' && $csrf->isValid()) {
    $deleteId = rex_request('id', 'int', 0);
    if ($deleteId > 0) {
        PastebinService::deleteEntry($deleteId);
        echo rex_view::success('Pastebin-Eintrag geloescht.');
    }
}

if (rex_request('create_paste', 'int', 0) === 1) {
    if (!$csrf->isValid()) {
        echo rex_view::error('Aktion abgelehnt (ungueltiger CSRF-Token).');
    } else {
        $title = trim(rex_request('title', 'string', ''));
        $secret = trim(rex_request('secret_content', 'string', ''));
        $password = rex_request('access_password', 'string', '');
        $expiresRaw = trim(rex_request('expires_at', 'string', ''));
        $selectedFiles = rex_request('attachment_files', 'array', []);

        $availableMedia = PastebinService::getMediaForCategory($selectedCategoryId);
        $allowedFilenames = [];
        foreach ($availableMedia as $media) {
            $allowedFilenames[] = $media['filename'];
        }

        $attachments = [];
        foreach ($selectedFiles as $filename) {
            if (!is_string($filename) || $filename === '') {
                continue;
            }
            if (in_array($filename, $allowedFilenames, true)) {
                $attachments[] = $filename;
            }
        }

        if ($secret === '') {
            echo rex_view::error('Bitte geheimen Inhalt eingeben.');
        } else {
            $expiresAt = null;
            if ($expiresRaw !== '') {
                $ts = strtotime($expiresRaw);
                if ($ts !== false) {
                    $expiresAt = date('Y-m-d H:i:s', $ts);
                }
            }

            $token = PastebinService::createPaste(
                $title,
                $secret,
                $attachments,
                $password !== '' ? $password : null,
                $expiresAt,
                $user->getLogin()
            );

            $frontendBase = rtrim((string) rex::getServer(), '/');
            $createdUrl = $frontendBase . '/index.php?klxm_paste=' . rawurlencode($token);
            echo rex_view::success('Eintrag erstellt. Link unten kopieren. Hinweis: Vernichtung erfolgt nach Abruf.');
        }
    }
}

$categorySelect = new rex_media_category_select(false);
$categorySelect->setName('paste_media_category');
$categorySelect->setId('paste_media_category');
$categorySelect->setSelected($selectedCategoryId);
$categorySelect->setSize(1);
$categorySelect->setAttribute('class', 'form-control selectpicker');
$categorySelect->setAttribute('data-live-search', 'true');
$categorySelect->setAttribute('onchange', 'this.form.submit();');

$mediaRows = $selectedCategoryId > 0 ? PastebinService::getMediaForCategory($selectedCategoryId) : [];
$entries = PastebinService::getEntries();

echo '<div class="panel panel-primary">';
echo '<div class="panel-heading"><h3 class="panel-title">Pastebin (Einmalabruf)</h3></div>';
echo '<div class="panel-body">';
echo '<p>Fuer Passwoerter, Zertifikate oder sensible Texte. Nach dem ersten Abruf wird der Datensatz vernichtet.</p>';

if ($createdUrl !== '') {
    echo '<div class="alert alert-success"><strong>Pastebin-Link:</strong><br>';
    echo '<input class="form-control" type="text" readonly value="' . htmlspecialchars($createdUrl) . '">';
    echo '</div>';
}

echo '<form method="get" class="form-inline" style="margin-bottom:15px;">';
echo '<input type="hidden" name="page" value="klxm_restricted/pastebin">';
echo '<div class="form-group" style="min-width:360px;">';
echo '<label for="paste_media_category" style="margin-right:8px;">Anhang-Kategorie</label>';
echo $categorySelect->get();
echo '</div>';
echo '</form>';

echo '<form method="post">';
echo '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($csrf->getValue()) . '">';
echo '<input type="hidden" name="create_paste" value="1">';
echo '<input type="hidden" name="paste_media_category" value="' . $selectedCategoryId . '">';

echo '<div class="form-group"><label for="title">Titel (optional)</label>';
echo '<input id="title" class="form-control" type="text" name="title" maxlength="191"></div>';

echo '<div class="form-group"><label for="secret_content">Geheimer Inhalt</label>';
echo '<textarea id="secret_content" class="form-control" rows="8" name="secret_content" required></textarea></div>';

echo '<div class="form-group"><label for="access_password">Optionales Zugriffspasswort</label>';
echo '<input id="access_password" class="form-control" type="text" name="access_password"></div>';

echo '<div class="form-group"><label for="expires_at">Ablauf (optional)</label>';
echo '<input id="expires_at" class="form-control" type="datetime-local" name="expires_at"></div>';

if ($selectedCategoryId > 0 && $mediaRows !== []) {
    echo '<hr><h4>Optionale Anhaenge</h4>';
    echo '<div style="max-height:220px; overflow:auto; border:1px solid #ddd; padding:10px;">';
    foreach ($mediaRows as $media) {
        $filename = $media['filename'];
        $label = $media['title'] !== '' ? $media['title'] . ' (' . $filename . ')' : $filename;
        echo '<div class="checkbox"><label>';
        echo '<input type="checkbox" name="attachment_files[]" value="' . htmlspecialchars($filename) . '"> ' . htmlspecialchars($label);
        echo '</label></div>';
    }
    echo '</div>';
}

echo '<div style="margin-top:15px;">';
echo '<button type="submit" class="btn btn-primary">Pastebin-Link erstellen</button>';
echo '</div>';

echo '</form>';
echo '</div></div>';

echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">Eintraege</h3></div>';
echo '<div class="panel-body">';
if ($entries === []) {
    echo rex_view::info('Noch keine Eintraege vorhanden.');
} else {
    echo '<div class="table-responsive"><table class="table table-striped table-hover">';
    echo '<thead><tr><th>ID</th><th>Titel</th><th>Status</th><th>Views</th><th>Ablauf</th><th>Token-Hinweis</th><th>Erstellt von</th><th>Erstellt</th><th>Zerstoert</th><th></th></tr></thead><tbody>';
    foreach ($entries as $entry) {
        $deleteUrl = rex_url::backendController(array_merge([
            'page' => 'klxm_restricted/pastebin',
            'func' => 'delete',
            'id' => (int) $entry['id'],
            'paste_media_category' => $selectedCategoryId,
        ], $csrf->getUrlParams()));

        echo '<tr>';
        echo '<td>' . (int) $entry['id'] . '</td>';
        echo '<td>' . htmlspecialchars((string) $entry['title']) . '</td>';
        echo '<td>' . ((int) $entry['status'] === 1 ? 'aktiv' : 'vernichtet') . '</td>';
        echo '<td>' . (int) $entry['view_count'] . '</td>';
        echo '<td>' . htmlspecialchars((string) $entry['expires_at']) . '</td>';
        echo '<td><code>' . htmlspecialchars((string) $entry['token_hint']) . '</code></td>';
        echo '<td>' . htmlspecialchars((string) $entry['created_by']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $entry['createdate']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $entry['destroyedate']) . '</td>';
        echo '<td><a class="btn btn-xs btn-danger" href="' . $deleteUrl . '" onclick="return confirm(\'Eintrag wirklich loeschen?\');">Loeschen</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div>';
