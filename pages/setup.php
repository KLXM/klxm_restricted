<?php
use KLXM\Restricted\Tools\SetupService;

$addon = rex_addon::get('klxm_restricted');
$csrf = rex_csrf_token::factory('klxm_restricted_setup');

$report = [];

if (rex_post('sync_modules', 'bool') && $csrf->isValid()) {
    $report = SetupService::syncModules();
}

$content = '';

// Intro
$content .= rex_view::info($addon->i18n('klxm_restricted_setup_intro'));

// Action-Form
$formContent = '';
$formContent .= '<div class="rex-form-group form-group">';
$formContent .= '<button class="btn btn-primary" type="submit" name="sync_modules" value="1">';
$formContent .= '<i class="rex-icon fa-refresh"></i> ' . $addon->i18n('klxm_restricted_setup_action_sync_modules');
$formContent .= '</button>';
$formContent .= '</div>';
$formContent .= $csrf->getHiddenField();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', $addon->i18n('klxm_restricted_setup_action_sync_modules'), false);
$fragment->setVar('body', '<form method="post">' . $formContent . '</form>', false);
$content .= $fragment->parse('core/page/section.php');

// Report
if ($report !== []) {
    $rows = '';
    foreach ($report as $line) {
        $statusClass = match ($line['status']) {
            'created' => 'success',
            'updated' => 'info',
            'error' => 'danger',
            default => 'default',
        };
        $statusLabel = match ($line['status']) {
            'created' => 'Neu angelegt',
            'updated' => 'Aktualisiert',
            'error' => 'Fehler',
            default => $line['status'],
        };
        $rows .= '<tr>';
        $rows .= '<td><span class="rex-online-status ' . $statusClass . '"></span> <strong>' . rex_escape($line['key']) . '</strong></td>';
        $rows .= '<td>' . rex_escape($line['message']) . '</td>';
        $rows .= '<td><span class="label label-' . $statusClass . '">' . rex_escape($statusLabel) . '</span></td>';
        $rows .= '</tr>';
    }

    $reportContent = '<table class="table table-striped">';
    $reportContent .= '<thead><tr>';
    $reportContent .= '<th>' . $addon->i18n('klxm_restricted_setup_col_key') . '</th>';
    $reportContent .= '<th>' . $addon->i18n('klxm_restricted_setup_col_message') . '</th>';
    $reportContent .= '<th>' . $addon->i18n('klxm_restricted_setup_col_status') . '</th>';
    $reportContent .= '</tr></thead>';
    $reportContent .= '<tbody>' . $rows . '</tbody>';
    $reportContent .= '</table>';

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', $addon->i18n('klxm_restricted_setup_report'), false);
    $fragment->setVar('body', $reportContent, false);
    $content .= $fragment->parse('core/page/section.php');
}

echo $content;
