<?php

$selectedShareId = (int) 'REX_VALUE[1]';

$currentArticleId = rex_request('article_id', 'int', 0);
if ($currentArticleId <= 0) {
	$currentArticleId = rex_request('id', 'int', 0);
}

$shareRows = rex_sql::factory()->getArray(
	'SELECT id, title, article_id, share_mode FROM ' . rex::getTable('klxm_restricted_file_share') . ' WHERE status = 1 ORDER BY id DESC'
);

$options = '<option value="0">Automatisch (neueste Freigabe der aktuellen Seite)</option>';
foreach ($shareRows as $row) {
	$id = (int) ($row['id'] ?? 0);
	if ($id <= 0) {
		continue;
	}

	$title = trim((string) ($row['title'] ?? ''));
	if ($title === '') {
		$title = 'Ohne Titel';
	}

	$mode = trim((string) ($row['share_mode'] ?? 'article'));
	$articleId = (int) ($row['article_id'] ?? 0);
	$meta = 'ID ' . $id . ' | Modus: ' . $mode;
	if ($articleId > 0) {
		$meta .= ' | Artikel: ' . $articleId;
	}

	$selected = $id === $selectedShareId ? ' selected' : '';
	$options .= '<option value="' . $id . '"' . $selected . '>' . htmlspecialchars($title . ' (' . $meta . ')') . '</option>';
}

echo '<p>Dieses Modul zeigt die KLXM-Dateifreigabe inklusive Einzel- und ZIP-Downloads an.</p>';
echo '<div class="form-group">';
echo '<label for="rex-value-1">Freigabe</label>';
echo '<select class="form-control" id="rex-value-1" name="REX_INPUT_VALUE[1]">' . $options . '</select>';
echo '<p class="help-block">Bei "Automatisch" wird wie bisher die neueste aktive Artikel-Freigabe verwendet.</p>';
echo '</div>';

if ($selectedShareId === 0 && $currentArticleId > 0) {
	$activeShareCountRows = rex_sql::factory()->getArray(
		'SELECT COUNT(*) AS c FROM ' . rex::getTable('klxm_restricted_file_share') . ' WHERE (share_mode = ? OR share_mode IS NULL OR share_mode = \'\') AND article_id = ? AND status = 1',
		['article', $currentArticleId]
	);
	$activeShareCount = (int) (($activeShareCountRows[0]['c'] ?? 0));

	if ($activeShareCount > 1) {
		echo rex_view::warning('Für diesen Artikel existieren mehrere aktive Freigaben. Im Modus „Automatisch“ wird die neueste Freigabe verwendet. Bitte eine konkrete Freigabe auswählen.');
	}
}
