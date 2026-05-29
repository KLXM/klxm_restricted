<?php

declare(strict_types=1);

namespace KLXM\Restricted;

use rex_addon;
use rex_config_form;
use rex_fragment;

$addon = rex_addon::get('klxm_restricted');

$form = rex_config_form::factory($addon->getName());

$field = $form->addLinkmapField('login_article');
$field->setLabel('Login Artikel');
$field->setNotice('Der Artikel, auf den Weitergeleitet wird, wenn Rechte fehlen.');

$field = $form->addLinkmapField('redirect_article_after_login');
$field->setLabel('Nach Login hierhin (Standard)');
$field->setNotice('Fallback-Artikel, auf den Weitergeleitet wird, wenn keine `redirect_to` Parameter gesetzt wurde.');

$field = $form->addSelectField('theme_framework');
$field->setLabel('CSS Framework für Formulare');
$select = $field->getSelect();
$select->addOption('Bootstrap', 'bootstrap');
$select->addOption('UIkit 3', 'uikit3');
$select->addOption('Tailwind', 'tailwind');

$field = $form->addInputField('number', 'max_login_attempts', null, ['min' => '1', 'max' => '20', 'class' => 'form-control']);
$field->setLabel('Max. Anmeldeversuche');
$field->setNotice('Anzahl Fehlversuche, bevor ein Konto vorübergehend gesperrt wird (Standard: 5).');
if ((string) $field->getValue() === '') {
    $field->setValue('5');
}

$field = $form->addInputField('number', 'lockout_minutes', null, ['min' => '1', 'max' => '1440', 'class' => 'form-control']);
$field->setLabel('Sperrdauer (Minuten)');
$field->setNotice('Wie lange ein Konto nach zu vielen Fehlversuchen gesperrt bleibt (Standard: 15).');
if ((string) $field->getValue() === '') {
    $field->setValue('15');
}

$field = $form->addInputField('number', 'session_timeout_minutes', null, ['min' => '1', 'max' => '1440', 'class' => 'form-control']);
$field->setLabel('Session Timeout (Minuten)');
$field->setNotice('Inaktivitaetsgrenze fuer DB-Sessions (Standard: 120).');
if ((string) $field->getValue() === '') {
    $field->setValue('120');
}

$field = $form->addInputField('number', 'session_max_lifetime_minutes', null, ['min' => '5', 'max' => '10080', 'class' => 'form-control']);
$field->setLabel('Maximale Session-Laufzeit (Minuten)');
$field->setNotice('Absolute Laufzeit einer Session unabhaengig von Aktivitaet (Standard: 1440).');
if ((string) $field->getValue() === '') {
    $field->setValue('1440');
}

$field = $form->addCheckboxField('require_email_verification');
$field->setLabel('E-Mail-Verifizierung erforderlich');
$field->addOption('Neue Nutzer müssen E-Mail-Adresse bestätigen', '1');

$field = $form->addCheckboxField('allow_guest_access_requests');
$field->setLabel('Zugriffsanfragen von Gästen');
$field->addOption('Nicht eingeloggte Besucher dürfen Zugriffsanfragen stellen', '1');
if ((string) $field->getValue() === '') {
    $field->setValue('1');
}

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', 'Einstellungen', false);
$fragment->setVar('body', $form->get(), false);

echo $fragment->parse('core/page/section.php');
