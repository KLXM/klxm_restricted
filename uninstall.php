<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex_addon;
use rex;
use rex_sql;
use rex_sql_table;

$tables = [
	'klxm_restricted_matrix',
	'klxm_restricted_media_share', // legacy table name
	'klxm_restricted_pastebin',
	'klxm_restricted_passkey',
	'klxm_restricted_access_request',
	'klxm_restricted_session',
	'klxm_restricted_file_share',
	'klxm_restricted_file_share_request',
	'klxm_restricted_file_share_download',
	'klxm_restricted_file_share_zip_job',
	'klxm_restricted_user',
	'klxm_restricted_role',
];

foreach ($tables as $tableName) {
	try {
		rex_sql_table::get(rex::getTable($tableName))->drop();
	} catch (\Throwable) {
		// Ignore missing tables during uninstall.
	}
}

if (rex_addon::get('yform')->isAvailable()) {
	$tableNames = [];
	foreach ($tables as $tableName) {
		$tableNames[] = rex::getTable($tableName);
	}

	if ($tableNames !== []) {
		$placeholders = implode(',', array_fill(0, count($tableNames), '?'));
		$sql = rex_sql::factory();
		try {
			$sql->setQuery('DELETE FROM ' . rex::getTable('yform_field') . ' WHERE table_name IN (' . $placeholders . ')', $tableNames);
			$sql->setQuery('DELETE FROM ' . rex::getTable('yform_table') . ' WHERE table_name IN (' . $placeholders . ')', $tableNames);
		} catch (\Throwable) {
			// Ignore missing YForm metadata tables during uninstall.
		}
	}
}
