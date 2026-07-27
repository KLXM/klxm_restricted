<?php
use KLXM\Restricted\Auth;
use KLXM\Restricted\Frontend\UserController;

if (!rex_addon::get('klxm_restricted')->isAvailable()) {
    return;
}

$auth = new Auth();
$userController = new UserController();
$theme = rex_addon::get('klxm_restricted')->getConfig('theme_framework', 'bootstrap');
$error = '';
$success = '';

$csrf = rex_csrf_token::factory('klxm_restricted_register');

if (rex_request::post('klxm_action', 'string') === 'register') {
    if (!$csrf->isValid()) {
        $error = 'Aktion abgelehnt (ungültiger CSRF-Token).';
    } else {
        $defaultRoleId = (int) rex_addon::get('klxm_restricted')->getConfig('default_role_id', 0);
        $result = $userController->register(
            rex_request::post('email', 'string', ''),
            rex_request::post('password', 'string', ''),
            rex_request::post('firstname', 'string', ''),
            rex_request::post('lastname', 'string', ''),
            $defaultRoleId
        );
        $result['status'] ? $success = $result['message'] : $error = $result['message'];
    }
}

$actionUrl = rex_getUrl(rex_article::getCurrentId());

$fragment = new rex_fragment();
$fragment->setVar('action_url', $actionUrl, false);
$fragment->setVar('csrf_token', $csrf->getValue(), false);
$fragment->setVar('firstname', rex_request::post('firstname', 'string', ''));
$fragment->setVar('lastname', rex_request::post('lastname', 'string', ''));
$fragment->setVar('email', rex_request::post('email', 'string', ''));
$fragment->setVar('error', $error, false);
$fragment->setVar('success', $success, false);
echo $fragment->parse('restricted/' . $theme . '/register.php');
