<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex;
use rex_csrf_token;
use rex_request;
use rex_sql;
use rex_url;
use rex_view;

$token = rex_csrf_token::factory('klxm_restricted_requests');
$func = rex_request::get('func', 'string', '');
$requestId = rex_request::get('request_id', 'int', 0);

if ($func !== '' && $requestId > 0) {
    if (!$token->isValid()) {
        echo rex_view::error('Aktion abgelehnt (ungueltiger CSRF-Token).');
    } else {
        $status = null;
        if ($func === 'approve') {
            $status = 'approved';
        } elseif ($func === 'reject') {
            $status = 'rejected';
        }

        if ($status !== null) {
            $sqlUpdate = rex_sql::factory();
            $sqlUpdate->setTable(rex::getTable('klxm_restricted_access_request'));
            $sqlUpdate->setWhere(['id' => $requestId]);
            $sqlUpdate->setValue('status', $status);
            $sqlUpdate->setValue('updatedate', date('Y-m-d H:i:s'));
            $sqlUpdate->setValue('handled_by', rex::getUser()?->getName() ?? '');
            $sqlUpdate->update();
            echo rex_view::success('Anfrage wurde auf "' . $status . '" gesetzt.');
        }
    }
}

$filter = rex_request::get('status_filter', 'string', 'open');
$allowedFilter = ['open', 'approved', 'rejected', 'all'];
if (!in_array($filter, $allowedFilter, true)) {
    $filter = 'open';
}

$sql = rex_sql::factory();
$query = 'SELECT r.id, r.article_id, r.user_id, r.email, r.message, r.status, r.createdate, r.updatedate, r.handled_by, u.firstname, u.lastname '
    . 'FROM ' . rex::getTable('klxm_restricted_access_request') . ' r '
    . 'LEFT JOIN ' . rex::getTable('klxm_restricted_user') . ' u ON u.id = r.user_id';
$params = [];
if ($filter !== 'all') {
    $query .= ' WHERE r.status = ?';
    $params[] = $filter;
}
$query .= ' ORDER BY r.createdate DESC';
$rows = $sql->getArray($query, $params);

$filterLinks = [];
foreach ($allowedFilter as $filterOption) {
    $url = rex_url::backendController([
        'page' => 'klxm_restricted/requests',
        'status_filter' => $filterOption,
    ]);
    $label = strtoupper($filterOption);
    if ($filterOption === 'all') {
        $label = 'ALLE';
    }
    $filterLinks[] = $filterOption === $filter ? '<strong>' . $label . '</strong>' : '<a href="' . $url . '">' . $label . '</a>';
}

echo '<p>Status: ' . implode(' | ', $filterLinks) . '</p>';

if (count($rows) === 0) {
    echo rex_view::info('Keine Zugriffsanfragen vorhanden.');
    return;
}

$table = '<table class="table table-striped table-hover">';
$table .= '<thead><tr>'
    . '<th>ID</th>'
    . '<th>Inhalt</th>'
    . '<th>Nutzer / E-Mail</th>'
    . '<th>Nachricht</th>'
    . '<th>Status</th>'
    . '<th>Erstellt</th>'
    . '<th>Aktion</th>'
    . '</tr></thead><tbody>';

foreach ($rows as $row) {
    $articleId = (int) $row['article_id'];
    $article = \rex_article::get($articleId);
    $articleLabel = $article ? $article->getName() . ' [' . $articleId . ']' : 'Artikel ' . $articleId;

    $userLabel = trim((string) ($row['firstname'] ?? '') . ' ' . (string) ($row['lastname'] ?? ''));
    if ($userLabel === '') {
        $userLabel = '-';
    }
    $email = (string) $row['email'];
    $message = nl2br(htmlspecialchars((string) ($row['message'] ?? '')));
    $status = (string) $row['status'];

    $actions = '-';
    if ($status === 'open') {
        $approveUrl = rex_url::backendController(array_merge([
            'page' => 'klxm_restricted/requests',
            'func' => 'approve',
            'request_id' => (int) $row['id'],
            'status_filter' => $filter,
        ], $token->getUrlParams()));
        $rejectUrl = rex_url::backendController(array_merge([
            'page' => 'klxm_restricted/requests',
            'func' => 'reject',
            'request_id' => (int) $row['id'],
            'status_filter' => $filter,
        ], $token->getUrlParams()));

        $actions = '<a class="btn btn-xs btn-success" href="' . $approveUrl . '">Freigeben</a> '
            . '<a class="btn btn-xs btn-danger" href="' . $rejectUrl . '">Ablehnen</a>';
    }

    $table .= '<tr>'
        . '<td>' . (int) $row['id'] . '</td>'
        . '<td>' . htmlspecialchars($articleLabel) . '</td>'
        . '<td>' . htmlspecialchars($userLabel) . '<br><small>' . htmlspecialchars($email) . '</small></td>'
        . '<td>' . $message . '</td>'
        . '<td>' . htmlspecialchars($status) . '</td>'
        . '<td>' . htmlspecialchars((string) $row['createdate']) . '</td>'
        . '<td>' . $actions . '</td>'
        . '</tr>';
}

$table .= '</tbody></table>';

echo $table;
