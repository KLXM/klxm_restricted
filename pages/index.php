<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex_addon;
use rex_be_controller;
use rex_url;
use rex_view;

$addon = rex_addon::get('klxm_restricted');

echo rex_view::title($addon->i18n('klxm_restricted_title'));

if (rex_addon::get('ycom')->isAvailable()) {
	$currentSubpage = (string) rex_be_controller::getCurrentPagePart(2);
	$allowedSubpages = ['', 'matrix', 'share_requests', 'pastebin', 'settings', 'editorial', 'help'];

	if (!in_array($currentSubpage, $allowedSubpages, true)) {
		$shareUrl = rex_url::backendController(['page' => 'mediapool/klxm_restricted_file_share']);
		echo rex_view::info('YCom ist aktiv: KLXM Restricted zeigt hier nur medienbezogene Funktionen. Nutzen Sie für Freigaben die Dateiablage im Medienpool.');
		echo '<p><a class="btn btn-primary" href="' . htmlspecialchars($shareUrl) . '">Zur Dateiablage teilen</a></p>';
		return;
	}
}

// Render the subpage
rex_be_controller::includeCurrentPageSubPath();
