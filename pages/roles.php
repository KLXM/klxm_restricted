<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex_extension;
use rex_view;
use rex_yform_manager;
use rex_yform_manager_table;

$tableName = 'rex_klxm_restricted_role';

rex_extension::register('YFORM_MANAGER_DATA_PAGE_HEADER', static function ($ep) {
    if ($ep->getParam('yform')->table->getTableName() === 'rex_klxm_restricted_role') {
        return '';
    }
});

$yform = new rex_yform_manager();
$table = rex_yform_manager_table::get($tableName);
if ($table) {
    $yform->setTable($table);
    $yform->setLinkVars(['page' => 'klxm_restricted/roles']);
    echo $yform->getDataPage();
} else {
    echo rex_view::error('YForm Tabelle "' . $tableName . '" nicht gefunden. Bitte AddOn neu installieren.');
}
