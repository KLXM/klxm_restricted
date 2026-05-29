<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex;
use rex_csrf_token;
use rex_request;
use rex_sql;
use rex_url;
use rex_view;

$token = rex_csrf_token::factory('klxm_restricted_sessions');
$selectedUserId = rex_request::get('user_id', 'int', 0);
$func = rex_request::get('func', 'string', '');
$sessionId = rex_request::get('session_id', 'string', '');

if ($func === 'delete' && $sessionId !== '') {
    if (!$token->isValid()) {
        echo rex_view::error('Aktion abgelehnt (ungueltiger CSRF-Token).');
    } else {
        $deleteSql = rex_sql::factory();
        $deleteSql->setTable(rex::getTable('klxm_restricted_session'));
        $deleteSql->setWhere('session_id = ?', [$sessionId]);
        $deleteSql->delete();

        echo rex_view::success('Session wurde beendet.');
    }
}

$users = rex_sql::factory()->getArray(
    'SELECT id, email, firstname, lastname FROM ' . rex::getTable('klxm_restricted_user') . ' ORDER BY lastname, firstname, email'
);

$where = '';
$params = [];
if ($selectedUserId > 0) {
    $where = ' WHERE s.user_id = ? ';
    $params[] = $selectedUserId;
}

$sessions = rex_sql::factory()->getArray(
    'SELECT s.session_id, s.user_id, s.ip, s.useragent, s.starttime, s.last_activity, '
    . 'u.email, u.firstname, u.lastname '
    . 'FROM ' . rex::getTable('klxm_restricted_session') . ' s '
    . 'LEFT JOIN ' . rex::getTable('klxm_restricted_user') . ' u ON u.id = s.user_id '
    . $where
    . 'ORDER BY s.last_activity DESC',
    $params
);

echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">Aktive DB-Sessions</h3></div>';
echo '<div class="panel-body">';

echo '<form method="get" class="form-inline" style="margin-bottom:15px;">';
echo '<input type="hidden" name="page" value="klxm_restricted/sessions">';
echo '<div class="form-group" style="margin-right:10px;">';
echo '<label for="klxm-user-filter" style="margin-right:8px;">Benutzer</label>';
echo '<select id="klxm-user-filter" name="user_id" class="form-control">';
echo '<option value="0">Alle</option>';
foreach ($users as $user) {
    $userId = (int) $user['id'];
    $name = trim((string) ($user['firstname'] . ' ' . $user['lastname']));
    if ($name === '') {
        $name = (string) $user['email'];
    }

    $selected = $selectedUserId === $userId ? ' selected' : '';
    echo '<option value="' . $userId . '"' . $selected . '>' . htmlspecialchars($name) . ' (#' . $userId . ')</option>';
}
echo '</select>';
echo '</div>';
echo '<button type="submit" class="btn btn-primary">Filtern</button> ';
echo '<a class="btn btn-default" href="' . rex_url::backendPage('klxm_restricted/sessions') . '">Reset</a>';
echo '</form>';

if ($sessions === []) {
    echo rex_view::info('Keine aktiven Sessions gefunden.');
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-striped table-hover">';
    echo '<thead><tr>';
    echo '<th>User</th>';
    echo '<th>Session-ID</th>';
    echo '<th>IP</th>';
    echo '<th>User-Agent</th>';
    echo '<th>Start</th>';
    echo '<th>Letzte Aktivitaet</th>';
    echo '<th></th>';
    echo '</tr></thead><tbody>';

    foreach ($sessions as $session) {
        $displayName = trim((string) ($session['firstname'] . ' ' . $session['lastname']));
        if ($displayName === '') {
            $displayName = (string) $session['email'];
        }

        $endUrl = rex_url::backendController(array_merge([
            'page' => 'klxm_restricted/sessions',
            'func' => 'delete',
            'session_id' => (string) $session['session_id'],
            'user_id' => $selectedUserId,
        ], $token->getUrlParams()));

        echo '<tr>';
        echo '<td>' . htmlspecialchars($displayName) . ' (#' . (int) $session['user_id'] . ')</td>';
        echo '<td><code>' . htmlspecialchars((string) $session['session_id']) . '</code></td>';
        echo '<td>' . htmlspecialchars((string) $session['ip']) . '</td>';
        echo '<td style="max-width:320px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="' . htmlspecialchars((string) $session['useragent']) . '">' . htmlspecialchars((string) $session['useragent']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $session['starttime']) . '</td>';
        echo '<td>' . htmlspecialchars((string) $session['last_activity']) . '</td>';
        echo '<td><a class="btn btn-xs btn-danger" href="' . $endUrl . '" onclick="return confirm(\'Diese Session beenden?\');">Beenden</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

echo '</div>';
echo '</div>';
