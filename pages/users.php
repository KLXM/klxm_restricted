<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use KLXM\Restricted\Backend\ImpersonateHandler;
use rex;
use rex_csrf_token;
use rex_extension;
use rex_extension_point;
use rex_request;
use rex_url;
use rex_view;
use rex_yform_manager;
use rex_yform_manager_table;

$tableName = 'rex_klxm_restricted_user';
$impersonateToken = rex_csrf_token::factory('klxm_restricted_impersonate');

// --- Handle impersonation actions (admin only) ---
$func = rex_request::get('func', 'string', '');
$impersonateUserId = rex_request::get('user_id', 'int', 0);

if (rex::getUser() !== null && rex::getUser()->isAdmin()) {
    if ($func === 'klxm_impersonate' && $impersonateUserId > 0) {
        if (!$impersonateToken->isValid()) {
            echo rex_view::error('Aktion abgelehnt (ungueltiger CSRF-Token).');
        } else {
        $result = ImpersonateHandler::start($impersonateUserId);
        if ($result['error'] !== '') {
            echo rex_view::error(htmlspecialchars($result['error']));
        } else {
            $frontendUrl = htmlspecialchars($result['frontendUrl']);
            echo rex_view::success(
                'Impersonation gestartet. '
                . '<a href="' . $frontendUrl . '" target="_blank" class="btn btn-sm btn-primary">Frontend öffnen &rarr;</a>'
            );
        }
        }
    } elseif ($func === 'klxm_stop_impersonate') {
        if (!$impersonateToken->isValid()) {
            echo rex_view::error('Aktion abgelehnt (ungueltiger CSRF-Token).');
        } else {
            ImpersonateHandler::stop();
            echo rex_view::success('Impersonation beendet.');
        }
    }

    $auth = new Auth();
    if ($auth->isImpersonated() && $auth->getUser() !== null) {
        $displayName = trim($auth->getUser()->firstname . ' ' . $auth->getUser()->lastname);
        if ($displayName === '') {
            $displayName = $auth->getUser()->email;
        }

        $stopUrl = rex_url::backendController(array_merge([
            'page' => 'klxm_restricted/users',
            'func' => 'klxm_stop_impersonate',
        ], $impersonateToken->getUrlParams()));

        echo rex_view::warning(
            'Imitation aktiv: Sie sehen aktuell das Frontend als <strong>'
            . htmlspecialchars($displayName)
            . '</strong>. '
            . '<a class="btn btn-xs btn-warning" href="' . $stopUrl . '">Imitation beenden</a>'
        );
    }
}

rex_extension::register('YFORM_MANAGER_DATA_PAGE_HEADER', static function (rex_extension_point $ep): string {
    /** @var \rex_yform_manager $yform */
    $yform = $ep->getParam('yform');
    if ($yform->table !== null && $yform->table->getTableName() === 'rex_klxm_restricted_user') {
        return '';
    }
    return (string) $ep->getSubject();
});

// Add "Als Nutzer anmelden" button to each row action dropdown (admins only)
if (rex::getUser() !== null && rex::getUser()->isAdmin()) {
    rex_extension::register('YFORM_DATA_LIST_ACTION_BUTTONS', static function (rex_extension_point $ep): mixed {
        /** @var \rex_yform_manager_table $table */
        $table = $ep->getParam('table');
        if ($table->getTableName() !== 'rex_klxm_restricted_user') {
            return $ep->getSubject();
        }

        $actionButtons = $ep->getSubject();
        $actionButtons['klxm_impersonate'] = [
            'params'     => [],
            'content'    => '<i class="rex-icon fa-user-secret"></i> Als Nutzer anmelden',
            'attributes' => [
                'onclick' => "return confirm('Diesen Nutzer im Frontend impersonieren?')",
            ],
            'url' => rex_url::backendController(array_merge([
                'page'    => 'klxm_restricted/users',
                'func'    => 'klxm_impersonate',
                'user_id' => '___id___',
            ], rex_csrf_token::factory('klxm_restricted_impersonate')->getUrlParams())),
        ];

        return $actionButtons;
    });
}

$yform = new rex_yform_manager();
$table = rex_yform_manager_table::get($tableName);
if ($table) {
    $yform->setTable($table);
    $yform->setLinkVars(['page' => 'klxm_restricted/users']);
    echo $yform->getDataPage();
} else {
    echo rex_view::error('YForm Tabelle "' . $tableName . '" nicht gefunden. Bitte AddOn neu installieren.');
}
