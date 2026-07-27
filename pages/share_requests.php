<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex;
use rex_addon;
use rex_article;
use rex_csrf_token;
use rex_dir;
use rex_file;
use rex_path;
use rex_request;
use rex_response;
use rex_sql;
use rex_url;
use rex_view;

$user = rex::requireUser();
$hasSharePermission = $user->isAdmin() || $user->hasPerm('klxm_restricted[share]');
if (!$hasSharePermission) {
    echo rex_view::error('Keine Berechtigung.');
    return;
}

$echartsAddon = rex_addon::get('echarts');
$hasEcharts = $echartsAddon->isAvailable();
if ($hasEcharts) {
    $sourceJs = rex_path::addon('klxm_restricted', 'assets/share-requests.js');
    $targetJs = rex_path::addonAssets('klxm_restricted', 'share-requests.js');
    if (
        is_file($sourceJs)
        && (!is_file($targetJs) || (int) @filemtime($targetJs) < (int) @filemtime($sourceJs))
    ) {
        rex_dir::create(dirname($targetJs));
        rex_file::copy($sourceJs, $targetJs);
    }
    rex_view::addJsFile($echartsAddon->getAssetsUrl('echarts.min.js'));
}

$csrf = rex_csrf_token::factory('klxm_restricted_share_requests');
$func = rex_request('func', 'string', '');
$shareIdFilter = rex_request('share_id', 'int', 0);
$fromDate = trim(rex_request('from_date', 'string', ''));
$toDate = trim(rex_request('to_date', 'string', ''));

if (in_array($func, ['export_csv', 'export_requests_csv', 'export_pdf'], true) && !$csrf->isValid()) {
    echo rex_view::error('Sicherheits-Token ungültig.');
    $func = '';
}

$shareOptions = rex_sql::factory()->getArray(
    'SELECT id, title FROM ' . rex::getTable('klxm_restricted_file_share') . ' ORDER BY id DESC'
);

$shareTitleById = [];
foreach ($shareOptions as $shareOption) {
    $optionId = (int) ($shareOption['id'] ?? 0);
    if ($optionId <= 0) {
        continue;
    }

    $optionTitle = trim((string) ($shareOption['title'] ?? ''));
    if ($optionTitle === '') {
        $optionTitle = 'Ohne Titel';
    }

    $shareTitleById[$optionId] = $optionTitle;
}

$selectedShareTitle = $shareIdFilter > 0 ? (string) ($shareTitleById[$shareIdFilter] ?? '') : '';
$hasPdfOut = rex_addon::get('pdfout')->isAvailable() && class_exists(\FriendsOfRedaxo\PdfOut\PdfOut::class);

$fromSql = null;
if ($fromDate !== '') {
    $fromTs = strtotime($fromDate . ' 00:00:00');
    if ($fromTs !== false) {
        $fromSql = date('Y-m-d H:i:s', $fromTs);
    }
}

$toSql = null;
if ($toDate !== '') {
    $toTs = strtotime($toDate . ' 23:59:59');
    if ($toTs !== false) {
        $toSql = date('Y-m-d H:i:s', $toTs);
    }
}

$baseQuery = ' FROM ' . rex::getTable('klxm_restricted_file_share_request') . ' req '
    . 'LEFT JOIN ' . rex::getTable('klxm_restricted_file_share') . ' share ON share.id = req.share_id ';

$where = [];
$params = [];
if ($shareIdFilter > 0) {
    $where[] = 'req.share_id = ?';
    $params[] = $shareIdFilter;
}
if ($fromSql !== null) {
    $where[] = 'req.createdate >= ?';
    $params[] = $fromSql;
}
if ($toSql !== null) {
    $where[] = 'req.createdate <= ?';
    $params[] = $toSql;
}
$whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

$downloadWhere = [];
$downloadParams = [];
if ($shareIdFilter > 0) {
    $downloadWhere[] = 'dl.share_id = ?';
    $downloadParams[] = $shareIdFilter;
}
if ($fromSql !== null) {
    $downloadWhere[] = 'dl.createdate >= ?';
    $downloadParams[] = $fromSql;
}
if ($toSql !== null) {
    $downloadWhere[] = 'dl.createdate <= ?';
    $downloadParams[] = $toSql;
}
$downloadWhereSql = $downloadWhere === [] ? '' : (' WHERE ' . implode(' AND ', $downloadWhere));

if ($func === 'export_csv') {
    $downloadRows = rex_sql::factory()->getArray(
        'SELECT dl.share_id, share.title AS share_title, dl.filename, m.title AS media_title, COUNT(*) AS downloads, MAX(dl.createdate) AS last_download '
        . 'FROM ' . rex::getTable('klxm_restricted_file_share_download') . ' dl '
        . 'LEFT JOIN ' . rex::getTable('klxm_restricted_file_share') . ' share ON share.id = dl.share_id '
        . 'LEFT JOIN ' . rex::getTable('media') . ' m ON m.filename = dl.filename '
        . $downloadWhereSql
        . ' GROUP BY dl.share_id, share.title, dl.filename, m.title '
        . ' ORDER BY downloads DESC, dl.share_id ASC, dl.filename ASC',
        $downloadParams
    );

    rex_response::cleanOutputBuffers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="share_downloads_' . date('Y-m-d_H-i-s') . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }

    fputcsv($out, ['share_id', 'share_title', 'media_title', 'filename', 'downloads', 'last_download'], ';', '"', '\\');
    foreach ($downloadRows as $row) {
        fputcsv($out, [
            (string) ($row['share_id'] ?? ''),
            (string) ($row['share_title'] ?? ''),
            (string) ($row['media_title'] ?? ''),
            (string) ($row['filename'] ?? ''),
            (string) ($row['downloads'] ?? ''),
            (string) ($row['last_download'] ?? ''),
        ], ';', '"', '\\');
    }

    fclose($out);
    exit;
}

if ($func === 'export_requests_csv') {
    $rows = rex_sql::factory()->getArray(
        'SELECT req.id, req.share_id, share.title AS share_title, req.article_id, req.request_email, req.request_payload, req.valid_until, req.mail_sent, req.createdate '
        . $baseQuery
        . $whereSql
        . ' ORDER BY req.id DESC',
        $params
    );

    $payloadKeys = [];
    $payloadRows = [];
    foreach ($rows as $index => $row) {
        $payloadMap = [];
        $payloadRaw = trim((string) ($row['request_payload'] ?? ''));
        if ($payloadRaw !== '') {
            $decoded = json_decode($payloadRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    $column = trim((string) $key);
                    if ($column === '') {
                        continue;
                    }

                    if (!in_array($column, $payloadKeys, true)) {
                        $payloadKeys[] = $column;
                    }

                    if (is_scalar($value) || $value === null) {
                        $payloadMap[$column] = (string) $value;
                    } else {
                        $payloadMap[$column] = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
            }
        }

        $payloadRows[$index] = $payloadMap;
    }

    rex_response::cleanOutputBuffers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="share_requests_' . date('Y-m-d_H-i-s') . '.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }

    $header = ['id', 'share_id', 'share_title', 'article_id', 'request_email', 'valid_until', 'mail_sent', 'createdate'];
    $header = array_merge($header, $payloadKeys);
    fputcsv($out, $header, ';', '"', '\\');

    foreach ($rows as $index => $row) {
        $csvRow = [
            (string) ($row['id'] ?? ''),
            (string) ($row['share_id'] ?? ''),
            (string) ($row['share_title'] ?? ''),
            (string) ($row['article_id'] ?? ''),
            (string) ($row['request_email'] ?? ''),
            (string) ($row['valid_until'] ?? ''),
            (string) ($row['mail_sent'] ?? ''),
            (string) ($row['createdate'] ?? ''),
        ];

        $payloadMap = $payloadRows[$index] ?? [];
        foreach ($payloadKeys as $payloadKey) {
            $csvRow[] = (string) ($payloadMap[$payloadKey] ?? '');
        }

        fputcsv($out, $csvRow, ';', '"', '\\');
    }

    fclose($out);
    exit;
}

if ($func === 'export_pdf' && $hasPdfOut) {
    if ($shareIdFilter <= 0) {
        echo rex_view::error('Bitte zuerst eine Freigabe auswählen. Der PDF-Bericht wird immer für genau eine ausgewählte Freigabe erzeugt.');
        $func = '';
    } else {
    $pdfRows = rex_sql::factory()->getArray(
        'SELECT req.id, req.share_id, share.title AS share_title, req.request_email, req.valid_until, req.mail_sent, req.createdate '
        . $baseQuery
        . $whereSql
        . ' ORDER BY req.id DESC LIMIT 500',
        $params
    );

    $pdfTopShares = rex_sql::factory()->getArray(
        'SELECT dl.share_id, COUNT(*) AS file_downloads, COUNT(DISTINCT dl.filename) AS unique_files, MAX(dl.createdate) AS last_download, share.title AS share_title '
        . 'FROM ' . rex::getTable('klxm_restricted_file_share_download') . ' dl '
        . 'LEFT JOIN ' . rex::getTable('klxm_restricted_file_share') . ' share ON share.id = dl.share_id '
        . $downloadWhereSql
        . ' GROUP BY dl.share_id, share.title '
        . ' ORDER BY file_downloads DESC, dl.share_id ASC LIMIT 20',
        $downloadParams
    );

    $pdfTopFiles = rex_sql::factory()->getArray(
        'SELECT dl.share_id, dl.filename, m.title AS media_title, COUNT(*) AS downloads, MAX(dl.createdate) AS last_download, share.title AS share_title '
        . 'FROM ' . rex::getTable('klxm_restricted_file_share_download') . ' dl '
        . 'LEFT JOIN ' . rex::getTable('klxm_restricted_file_share') . ' share ON share.id = dl.share_id '
        . 'LEFT JOIN ' . rex::getTable('media') . ' m ON m.filename = dl.filename '
        . $downloadWhereSql
        . ' GROUP BY dl.share_id, dl.filename, m.title, share.title '
        . ' ORDER BY downloads DESC, dl.share_id ASC, dl.filename ASC LIMIT 40',
        $downloadParams
    );

    $totalRequests = count($pdfRows);
    $uniqueEmailMap = [];
    $sentMails = 0;
    foreach ($pdfRows as $entry) {
        $email = trim((string) ($entry['request_email'] ?? ''));
        if ($email !== '') {
            $uniqueEmailMap[strtolower($email)] = true;
        }
        if ((int) ($entry['mail_sent'] ?? 0) === 1) {
            $sentMails++;
        }
    }
    $uniqueEmails = count($uniqueEmailMap);

    $shareLabel = $shareIdFilter > 0 ? ('#' . $shareIdFilter . ' ' . ($selectedShareTitle !== '' ? $selectedShareTitle : 'Ohne Titel')) : 'Alle Freigaben';
    $periodLabel = ($fromDate !== '' ? $fromDate : 'Anfang') . ' bis ' . ($toDate !== '' ? $toDate : 'Heute');

    ob_start();
    ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Freigabe-Report</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #102033; margin: 24px; }
        .header { border-bottom: 3px solid #2a7de1; padding-bottom: 10px; margin-bottom: 16px; }
        .title { font-size: 24px; font-weight: 700; margin: 0 0 6px; }
        .meta { color: #4f6378; font-size: 12px; }
        .cards { margin: 16px 0 20px; }
        .card { width: 31%; display: inline-block; margin-right: 2%; vertical-align: top; background: #f3f8ff; border: 1px solid #d4e4fb; border-radius: 6px; padding: 10px; box-sizing: border-box; }
        .card:last-child { margin-right: 0; }
        .card-label { font-size: 11px; color: #4f6378; text-transform: uppercase; }
        .card-value { font-size: 24px; font-weight: 700; color: #1f5ea8; margin-top: 2px; }
        h2 { font-size: 16px; margin: 18px 0 8px; color: #1f5ea8; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        th { background: #1f5ea8; color: #ffffff; font-size: 11px; text-align: left; padding: 7px; }
        td { font-size: 11px; padding: 6px 7px; border-bottom: 1px solid #dfe8f5; }
        tr:nth-child(even) td { background: #f8fbff; }
        .footer { margin-top: 18px; font-size: 10px; color: #6f8094; border-top: 1px solid #dfe8f5; padding-top: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">KLXM Restricted: Freigabe-Report</p>
        <div class="meta">
            <strong>Freigabe:</strong> <?= htmlspecialchars($shareLabel) ?> |
            <strong>Zeitraum:</strong> <?= htmlspecialchars($periodLabel) ?> |
            <strong>Erstellt:</strong> <?= date('d.m.Y H:i:s') ?>
        </div>
    </div>

    <div class="cards">
        <div class="card"><div class="card-label">Anfragen</div><div class="card-value"><?= $totalRequests ?></div></div>
        <div class="card"><div class="card-label">Eindeutige E-Mails</div><div class="card-value"><?= $uniqueEmails ?></div></div>
        <div class="card"><div class="card-label">Mails gesendet</div><div class="card-value"><?= $sentMails ?></div></div>
    </div>

    <h2>Top-Freigaben</h2>
    <table>
        <thead><tr><th>Share</th><th>Datei-Downloads</th><th>Unterschiedliche Dateien</th><th>Letzter Download</th></tr></thead>
        <tbody>
        <?php foreach ($pdfTopShares as $entry): ?>
            <tr>
                <td>#<?= (int) ($entry['share_id'] ?? 0) ?> <?= htmlspecialchars((string) ($entry['share_title'] ?? '')) ?></td>
                <td><?= (int) ($entry['file_downloads'] ?? 0) ?></td>
                <td><?= (int) ($entry['unique_files'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($entry['last_download'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Top-Dateien</h2>
    <table>
        <thead><tr><th>Share</th><th>Datei</th><th>Downloads</th><th>Letzter Download</th></tr></thead>
        <tbody>
        <?php foreach ($pdfTopFiles as $entry): ?>
            <tr>
                <td>#<?= (int) ($entry['share_id'] ?? 0) ?> <?= htmlspecialchars((string) ($entry['share_title'] ?? '')) ?></td>
                <td>
                    <?php $pdfMediaTitle = trim((string) ($entry['media_title'] ?? '')); ?>
                    <?php if ($pdfMediaTitle !== ''): ?>
                        <strong><?= htmlspecialchars($pdfMediaTitle) ?></strong><br>
                    <?php endif; ?>
                    <?= htmlspecialchars((string) ($entry['filename'] ?? '')) ?>
                </td>
                <td><?= (int) ($entry['downloads'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($entry['last_download'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Letzte Anfragen</h2>
    <table>
        <thead><tr><th>ID</th><th>Share</th><th>E-Mail</th><th>Gültig bis</th><th>Mail</th><th>Erfasst am</th></tr></thead>
        <tbody>
        <?php foreach ($pdfRows as $index => $entry): ?>
            <?php if ($index >= 30) { break; } ?>
            <tr>
                <td><?= (int) ($entry['id'] ?? 0) ?></td>
                <td>#<?= (int) ($entry['share_id'] ?? 0) ?> <?= htmlspecialchars((string) ($entry['share_title'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($entry['request_email'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($entry['valid_until'] ?? '')) ?></td>
                <td><?= (int) ($entry['mail_sent'] ?? 0) === 1 ? 'gesendet' : 'offen' ?></td>
                <td><?= htmlspecialchars((string) ($entry['createdate'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer">KLXM Restricted · REDAXO · PDFOut-Export</div>
</body>
</html>
<?php
    $pdfHtml = (string) ob_get_clean();

    $pdfName = 'freigabe_report_' . ($shareIdFilter > 0 ? ('share_' . $shareIdFilter . '_') : 'alle_') . date('Y-m-d_H-i-s');
    $pdf = new \FriendsOfRedaxo\PdfOut\PdfOut();
    $pdf->setName($pdfName)
        ->setAttachment(true)
        ->setDpi(150)
        ->setPaperSize('A4', 'portrait')
        ->setFont('DejaVu Sans')
        ->setHtml($pdfHtml)
        ->run();
    exit;
    }
}

echo '<form method="get" class="form-inline" style="margin-bottom:15px;">';
echo '<input type="hidden" name="page" value="klxm_restricted/share_requests">';
echo '<div class="form-group" style="margin-right:8px;">';
echo '<label for="share_id" style="margin-right:8px;">Freigabe</label>';
echo '<select class="form-control" id="share_id" name="share_id">';
echo '<option value="0">Bitte Freigabe wählen</option>';
foreach ($shareOptions as $shareOption) {
    $optionId = (int) ($shareOption['id'] ?? 0);
    if ($optionId <= 0) {
        continue;
    }

    $optionTitle = trim((string) ($shareOption['title'] ?? ''));
    if ($optionTitle === '') {
        $optionTitle = 'Ohne Titel';
    }

    echo '<option value="' . $optionId . '"' . ($shareIdFilter === $optionId ? ' selected' : '') . '>#' . $optionId . ' ' . htmlspecialchars($optionTitle) . '</option>';
}
echo '</select>';
echo '</div>';
echo '<div class="form-group" style="margin-right:8px;"><label for="from_date" style="margin-right:8px;">Von</label><input class="form-control" type="date" id="from_date" name="from_date" value="' . htmlspecialchars($fromDate) . '"></div>';
echo '<div class="form-group" style="margin-right:8px;"><label for="to_date" style="margin-right:8px;">Bis</label><input class="form-control" type="date" id="to_date" name="to_date" value="' . htmlspecialchars($toDate) . '"></div>';
echo '<button type="submit" class="btn btn-primary" style="margin-right:8px;">Anzeigen</button>';
echo '</form>';
echo '<p class="help-block" style="margin-top:0;">Hinweis: Der PDF-Bericht wird immer für die aktuell ausgewählte Freigabe erstellt.</p>';

if (!$hasEcharts && $shareIdFilter <= 0) {
    echo rex_view::info('Bitte zuerst eine Freigabe auswählen. Ohne ECharts wird die Detailstatistik erst nach Auswahl angezeigt.');
    return;
}

$totalRows = rex_sql::factory()->getArray('SELECT COUNT(*) AS cnt ' . $baseQuery . $whereSql, $params);
$total = (int) ($totalRows[0]['cnt'] ?? 0);

$uniqueRows = rex_sql::factory()->getArray('SELECT COUNT(DISTINCT req.request_email) AS cnt ' . $baseQuery . $whereSql, $params);
$unique = (int) ($uniqueRows[0]['cnt'] ?? 0);

$last30Rows = rex_sql::factory()->getArray(
    'SELECT COUNT(*) AS cnt ' . $baseQuery
    . ($whereSql === '' ? ' WHERE ' : $whereSql . ' AND ')
    . 'req.createdate >= ?',
    array_merge($params, [date('Y-m-d H:i:s', strtotime('-30 days'))])
);
$last30Count = (int) ($last30Rows[0]['cnt'] ?? 0);

$rows = rex_sql::factory()->getArray(
    'SELECT req.*, share.title AS share_title '
    . $baseQuery
    . $whereSql
    . ' ORDER BY req.id DESC LIMIT 500',
    $params
);

$topFiles = rex_sql::factory()->getArray(
    'SELECT dl.share_id, dl.filename, COUNT(*) AS downloads, MAX(dl.createdate) AS last_download, share.title AS share_title '
    . 'FROM ' . rex::getTable('klxm_restricted_file_share_download') . ' dl '
    . 'LEFT JOIN ' . rex::getTable('klxm_restricted_file_share') . ' share ON share.id = dl.share_id '
    . $downloadWhereSql
    . ' GROUP BY dl.share_id, dl.filename, share.title '
    . ' ORDER BY downloads DESC, dl.share_id ASC, dl.filename ASC LIMIT 50',
    $downloadParams
);

$topShares = rex_sql::factory()->getArray(
    'SELECT dl.share_id, COUNT(*) AS file_downloads, COUNT(DISTINCT dl.filename) AS unique_files, MAX(dl.createdate) AS last_download, share.title AS share_title '
    . 'FROM ' . rex::getTable('klxm_restricted_file_share_download') . ' dl '
    . 'LEFT JOIN ' . rex::getTable('klxm_restricted_file_share') . ' share ON share.id = dl.share_id '
    . $downloadWhereSql
    . ' GROUP BY dl.share_id, share.title '
    . ' ORDER BY file_downloads DESC, dl.share_id ASC LIMIT 20',
    $downloadParams
);

$topShareChartData = [];
foreach ($topShares as $entry) {
    if (count($topShareChartData) >= 12) {
        break;
    }

    $label = '#' . (int) ($entry['share_id'] ?? 0) . ' ' . trim((string) ($entry['share_title'] ?? ''));
    $topShareChartData[] = [
        'label' => trim($label),
        'value' => (int) ($entry['file_downloads'] ?? 0),
    ];
}

$modeRows = rex_sql::factory()->getArray(
    'SELECT dl.download_mode, COUNT(*) AS cnt '
    . 'FROM ' . rex::getTable('klxm_restricted_file_share_download') . ' dl '
    . $downloadWhereSql
    . ' GROUP BY dl.download_mode',
    $downloadParams
);

$modeCountMap = [
    'file' => 0,
    'zip_selected' => 0,
    'zip_all' => 0,
];
foreach ($modeRows as $modeRow) {
    $modeKey = (string) ($modeRow['download_mode'] ?? '');
    if (!array_key_exists($modeKey, $modeCountMap)) {
        continue;
    }

    $modeCountMap[$modeKey] = (int) ($modeRow['cnt'] ?? 0);
}

$modeChartData = [
    ['label' => 'Einzeldownload', 'value' => $modeCountMap['file']],
    ['label' => 'ZIP ausgewählt', 'value' => $modeCountMap['zip_selected']],
    ['label' => 'ZIP komplett', 'value' => $modeCountMap['zip_all']],
];

$trendWhere = $downloadWhere;
$trendParams = $downloadParams;
if ($fromSql === null && $toSql === null) {
    $trendWhere[] = 'dl.createdate >= ?';
    $trendParams[] = date('Y-m-d H:i:s', strtotime('-30 days'));
}
$trendWhereSql = $trendWhere === [] ? '' : (' WHERE ' . implode(' AND ', $trendWhere));

$trendRows = rex_sql::factory()->getArray(
    'SELECT DATE(dl.createdate) AS day, COUNT(*) AS cnt '
    . 'FROM ' . rex::getTable('klxm_restricted_file_share_download') . ' dl '
    . $trendWhereSql
    . ' GROUP BY DATE(dl.createdate) '
    . ' ORDER BY day ASC',
    $trendParams
);

$trendChartData = [];
foreach ($trendRows as $trendRow) {
    $day = (string) ($trendRow['day'] ?? '');
    if ($day === '') {
        continue;
    }

    $trendChartData[] = [
        'label' => $day,
        'value' => (int) ($trendRow['cnt'] ?? 0),
    ];
}

$chartJson = (string) json_encode([
    'topShares' => $topShareChartData,
    'downloadModes' => $modeChartData,
    'dailyTrend' => $trendChartData,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$mediaMetaByFilename = [];
$topFileNames = [];
foreach ($topFiles as $entry) {
    $name = trim((string) ($entry['filename'] ?? ''));
    if ($name !== '') {
        $topFileNames[] = $name;
    }
}
$topFileNames = array_values(array_unique($topFileNames));

if ($topFileNames !== []) {
    $placeholders = implode(',', array_fill(0, count($topFileNames), '?'));
    $mediaRows = rex_sql::factory()->getArray(
        'SELECT filename, title FROM ' . rex::getTable('media') . ' WHERE filename IN (' . $placeholders . ')',
        $topFileNames
    );
    foreach ($mediaRows as $mediaRow) {
        $filename = (string) ($mediaRow['filename'] ?? '');
        if ($filename === '') {
            continue;
        }

        $mediaMetaByFilename[$filename] = [
            'title' => trim((string) ($mediaRow['title'] ?? '')),
        ];
    }
}

$topFilesByShare = [];
foreach ($topFiles as $entry) {
    $sid = (int) ($entry['share_id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }

    if (!isset($topFilesByShare[$sid])) {
        $topFilesByShare[$sid] = [
            'share_title' => (string) ($entry['share_title'] ?? ''),
            'rows' => [],
            'total_downloads' => 0,
        ];
    }

    $topFilesByShare[$sid]['rows'][] = $entry;
    $topFilesByShare[$sid]['total_downloads'] += (int) ($entry['downloads'] ?? 0);
}

$imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'];
$renderTopFilePreview = static function (string $filename, string $displayName) use ($imageExtensions): string {
    $extension = strtolower((string) rex_file::extension($filename));
    $fileUrl = rex_url::media($filename);
    if (in_array($extension, $imageExtensions, true)) {
        return '<button type="button" class="btn btn-default btn-xs klxm-preview-trigger" '
            . 'data-preview-url="' . htmlspecialchars($fileUrl) . '" '
            . 'data-preview-filename="' . htmlspecialchars($filename) . '" '
            . 'data-preview-title="' . htmlspecialchars($displayName) . '" '
            . 'data-preview-image="1" '
            . 'style="padding:2px;border-color:#d7e3f0;">'
            . '<img src="' . htmlspecialchars($fileUrl) . '" alt="' . htmlspecialchars($displayName) . '" style="width:74px;height:48px;object-fit:cover;border:1px solid #dde7f2;border-radius:4px;">'
            . '</button>';
    }

    return '<button type="button" class="btn btn-default btn-xs klxm-preview-trigger" '
        . 'data-preview-url="' . htmlspecialchars($fileUrl) . '" '
        . 'data-preview-filename="' . htmlspecialchars($filename) . '" '
        . 'data-preview-title="' . htmlspecialchars($displayName) . '" '
        . 'data-preview-image="0" '
        . 'style="width:78px;height:52px;border-color:#dde7f2;background:#f6f9fd;">'
        . '<span class="label label-default" style="font-size:10px;">' . htmlspecialchars(strtoupper($extension !== '' ? $extension : 'FILE')) . '</span>'
        . '</button>';
};

$exportUrl = rex_url::backendController(array_merge([
    'page' => 'klxm_restricted/share_requests',
    'func' => 'export_csv',
    'share_id' => $shareIdFilter,
    'from_date' => $fromDate,
    'to_date' => $toDate,
], $csrf->getUrlParams()));

$exportRequestsUrl = rex_url::backendController(array_merge([
    'page' => 'klxm_restricted/share_requests',
    'func' => 'export_requests_csv',
    'share_id' => $shareIdFilter,
    'from_date' => $fromDate,
    'to_date' => $toDate,
], $csrf->getUrlParams()));

$exportPdfUrl = rex_url::backendController(array_merge([
    'page' => 'klxm_restricted/share_requests',
    'func' => 'export_pdf',
    'share_id' => $shareIdFilter,
    'from_date' => $fromDate,
    'to_date' => $toDate,
], $csrf->getUrlParams()));

echo '<div class="row">';
echo '<div class="col-sm-4"><div class="alert alert-info"><strong>Gesamt Anfragen:</strong> ' . $total . '</div></div>';
echo '<div class="col-sm-4"><div class="alert alert-info"><strong>Eindeutige E-Mails:</strong> ' . $unique . '</div></div>';
echo '<div class="col-sm-4"><div class="alert alert-info"><strong>Letzte 30 Tage:</strong> ' . $last30Count . '</div></div>';
echo '</div>';

if ($hasEcharts && $chartJson !== '') {
    echo '<script type="application/json" id="klxm-share-requests-chart-data">' . $chartJson . '</script>';
    echo '<div class="row">';
    echo '<div class="col-sm-6">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Top-Freigaben (Datei-Downloads)</h3></div>';
    echo '<div class="panel-body"><div id="klxm-share-chart-top-shares" style="height:300px;"></div></div>';
    echo '</div></div>';
    echo '<div class="col-sm-6">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Downloadarten</h3></div>';
    echo '<div class="panel-body"><div id="klxm-share-chart-modes" style="height:300px;"></div></div>';
    echo '</div></div>';
    echo '</div>';
    echo '<div class="row">';
    echo '<div class="col-sm-12">';
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading"><h3 class="panel-title">Zeitverlauf Downloads</h3></div>';
    echo '<div class="panel-body"><div id="klxm-share-chart-trend" style="height:280px;"></div></div>';
    echo '</div></div></div>';

}

echo '<div class="row">';
echo '<div class="col-sm-12">';
echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">Top-Freigaben nach Datei-Downloads</h3></div>';
echo '<div class="panel-body">';
if ($topShares === []) {
    echo rex_view::info('Noch keine Download-Ereignisse vorhanden.');
} else {
    echo '<div class="table-responsive"><table class="table table-striped table-hover table-condensed" style="margin-bottom:0;">';
    echo '<thead><tr><th>Share</th><th>Datei-Downloads</th><th>Unterschiedliche Dateien</th><th>Letzter Download</th></tr></thead><tbody>';
    foreach ($topShares as $entry) {
        echo '<tr>';
        echo '<td>#' . (int) ($entry['share_id'] ?? 0) . ' ' . htmlspecialchars((string) ($entry['share_title'] ?? '')) . '</td>';
        echo '<td>' . (int) ($entry['file_downloads'] ?? 0) . '</td>';
        echo '<td>' . (int) ($entry['unique_files'] ?? 0) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($entry['last_download'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div></div></div>';
echo '</div>';

echo '<div class="row">';
echo '<div class="col-sm-12">';
echo '<div class="panel panel-default">';
echo '<div class="panel-heading"><h3 class="panel-title">Top-Dateien pro Share (interaktiv)</h3></div>';
echo '<div class="panel-body">';
if ($topFiles === []) {
    echo rex_view::info('Noch keine Download-Ereignisse vorhanden.');
} else {
    echo '<div class="form-group" style="margin-bottom:10px;">';
    echo '<input type="text" class="form-control klxm-top-files-filter" data-target="klxm-top-files-groups" placeholder="Dateien nach Titel, Dateiname oder Share filtern">';
    echo '</div>';

    echo '<div class="panel-group" id="klxm-top-files-groups" role="tablist" aria-multiselectable="true">';
    $groupIndex = 0;
    foreach ($topFilesByShare as $shareId => $groupData) {
        $groupIndex++;
        $collapseId = 'klxm-top-files-share-' . (int) $shareId;
        $headingId = $collapseId . '-heading';
        $isFirst = $groupIndex === 1;
        $shareTitle = trim((string) ($groupData['share_title'] ?? ''));
        if ($shareTitle === '') {
            $shareTitle = 'Ohne Titel';
        }

        echo '<div class="panel panel-info klxm-top-files-panel" data-share="' . (int) $shareId . '">';
        echo '<div class="panel-heading" role="tab" id="' . $headingId . '">';
        echo '<h4 class="panel-title">';
        echo '<a role="button" data-toggle="collapse" data-parent="#klxm-top-files-groups" href="#' . $collapseId . '" aria-expanded="' . ($isFirst ? 'true' : 'false') . '" aria-controls="' . $collapseId . '">';
        echo '#' . (int) $shareId . ' ' . htmlspecialchars($shareTitle) . ' <small>(' . count($groupData['rows']) . ' Dateien, ' . (int) ($groupData['total_downloads'] ?? 0) . ' Downloads)</small>';
        echo '</a>';
        echo '</h4>';
        echo '</div>';
        echo '<div id="' . $collapseId . '" class="panel-collapse collapse' . ($isFirst ? ' in' : '') . '" role="tabpanel" aria-labelledby="' . $headingId . '">';
        echo '<div class="panel-body">';
        echo '<div class="table-responsive"><table class="table table-striped table-hover table-condensed">';
        echo '<thead><tr><th style="width:92px;">Vorschau</th><th>Datei</th><th style="width:110px;">Downloads</th><th style="width:170px;">Letzter Download</th></tr></thead><tbody>';

        foreach ($groupData['rows'] as $row) {
            $filename = trim((string) ($row['filename'] ?? ''));
            if ($filename === '') {
                continue;
            }

            $mediaTitle = trim((string) ($mediaMetaByFilename[$filename]['title'] ?? ''));
            $displayTitle = $mediaTitle !== '' ? $mediaTitle : $filename;
            $searchText = strtolower($displayTitle . ' ' . $filename . ' #' . (int) $shareId . ' ' . $shareTitle);

            echo '<tr class="klxm-top-file-row" data-search="' . htmlspecialchars($searchText) . '">';
            echo '<td>' . $renderTopFilePreview($filename, $displayTitle) . '</td>';
            echo '<td><strong>' . htmlspecialchars($displayTitle) . '</strong><br><small class="text-muted">' . htmlspecialchars($filename) . '</small></td>';
            echo '<td>' . (int) ($row['downloads'] ?? 0) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($row['last_download'] ?? '')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
}
echo '</div></div></div>';
echo '</div>';

echo '<div class="modal fade" id="klxm-preview-modal" tabindex="-1" role="dialog" aria-labelledby="klxm-preview-modal-label">';
echo '<div class="modal-dialog modal-lg" role="document">';
echo '<div class="modal-content">';
echo '<div class="modal-header">';
echo '<button type="button" class="close" data-dismiss="modal" aria-label="Schließen"><span aria-hidden="true">&times;</span></button>';
echo '<h4 class="modal-title" id="klxm-preview-modal-label">Dateivorschau</h4>';
echo '</div>';
echo '<div class="modal-body">';
echo '<div id="klxm-preview-modal-image-wrap" style="display:none;text-align:center;">';
echo '<img id="klxm-preview-modal-image" src="" alt="" style="max-width:100%;max-height:70vh;border:1px solid #d7e3f0;border-radius:4px;">';
echo '</div>';
echo '<div id="klxm-preview-modal-file-wrap" style="display:none;">';
echo '<p class="text-muted" style="margin-bottom:8px;">Für diesen Dateityp steht keine Inline-Vorschau zur Verfügung.</p>';
echo '<p><strong id="klxm-preview-modal-file-title"></strong><br><small id="klxm-preview-modal-filename" class="text-muted"></small></p>';
echo '</div>';
echo '</div>';
echo '<div class="modal-footer">';
echo '<a id="klxm-preview-modal-open" class="btn btn-primary" href="#" target="_blank" rel="noopener noreferrer">Datei öffnen</a>';
echo '<button type="button" class="btn btn-default" data-dismiss="modal">Schließen</button>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<p>';
echo '<a class="btn btn-default" href="' . $exportUrl . '">CSV Downloads</a> ';
echo '<a class="btn btn-default" href="' . $exportRequestsUrl . '">CSV Anfragen</a> ';
if ($hasPdfOut) {
    echo '<a class="btn btn-primary" href="' . $exportPdfUrl . '">PDF-Bericht</a>';
} else {
    echo '<button type="button" class="btn btn-default" disabled title="pdfout AddOn nicht verfügbar">PDF-Bericht (pdfout fehlt)</button>';
}
echo '</p>';

if ($rows === []) {
    echo rex_view::info('Keine Datensätze vorhanden.');
    return;
}

echo '<div class="table-responsive">';
echo '<table class="table table-striped table-hover">';
echo '<thead><tr>';
echo '<th>ID</th><th>Share</th><th>Artikel</th><th>E-Mail</th><th>Daten</th><th>IP-Hash</th><th>Gültig bis</th><th>Mail</th><th>Erfasst am</th>';
echo '</tr></thead><tbody>';

foreach ($rows as $row) {
    $payloadText = '';
    $payloadRaw = (string) ($row['request_payload'] ?? '');
    $payload = json_decode($payloadRaw, true);
    if (is_array($payload)) {
        $parts = [];
        foreach ($payload as $key => $value) {
            $parts[] = $key . ': ' . (is_string($value) ? $value : (string) json_encode($value));
        }
        $payloadText = implode(' | ', $parts);
    }

    $articleLabel = '';
    $articleId = (int) ($row['article_id'] ?? 0);
    if ($articleId > 0) {
        $article = rex_article::get($articleId);
        if ($article) {
            $articleLabel = $article->getName() . ' [' . $articleId . ']';
        }
    }

    echo '<tr>';
    echo '<td>' . (int) ($row['id'] ?? 0) . '</td>';
    echo '<td>#' . (int) ($row['share_id'] ?? 0) . ' ' . htmlspecialchars((string) ($row['share_title'] ?? '')) . '</td>';
    echo '<td>' . htmlspecialchars($articleLabel !== '' ? $articleLabel : (string) $articleId) . '</td>';
    echo '<td>' . htmlspecialchars((string) ($row['request_email'] ?? '')) . '</td>';
    echo '<td><small>' . htmlspecialchars($payloadText) . '</small></td>';
    echo '<td><small>' . htmlspecialchars((string) ($row['ip_hash'] ?? '')) . '</small></td>';
    echo '<td>' . htmlspecialchars((string) ($row['valid_until'] ?? '')) . '</td>';
    echo '<td>' . ((int) ($row['mail_sent'] ?? 0) === 1 ? '<span class="label label-success">gesendet</span>' : '<span class="label label-warning">offen</span>') . '</td>';
    echo '<td>' . htmlspecialchars((string) ($row['createdate'] ?? '')) . '</td>';
    echo '</tr>';
}

echo '</tbody></table></div>';
