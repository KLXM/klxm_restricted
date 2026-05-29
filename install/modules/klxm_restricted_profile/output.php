<?php
use KLXM\Restricted\Auth;
use KLXM\Restricted\Frontend\UserController;

if (!rex_addon::get('klxm_restricted')->isAvailable()) {
    return;
}

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    echo '<p>Bitte zuerst <a href="' . rex_getUrl(rex_addon::get('klxm_restricted')->getConfig('login_article')) . '">einloggen</a>.</p>';
    return;
}

$user = $auth->getUser();
$userController = new UserController();
$theme = rex_addon::get('klxm_restricted')->getConfig('theme_framework', 'bootstrap');
$error = '';
$success = '';

if (rex_request::post('klxm_action', 'string') === 'update_profile') {
    $result = $userController->updateProfile(
        $user,
        rex_request::post('email', 'string', ''),
        rex_request::post('firstname', 'string', ''),
        rex_request::post('lastname', 'string', '')
    );
    $result['status'] ? $success = $result['message'] : $error = $result['message'];
}

if (rex_request::post('klxm_action', 'string') === 'update_password') {
    $newPassword = rex_request::post('new_password', 'string', '');
    $newPasswordConfirm = rex_request::post('new_password_confirm', 'string', '');
    if ($newPassword !== $newPasswordConfirm) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } else {
        $result = $userController->updatePassword(
            $user,
            rex_request::post('current_password', 'string', ''),
            $newPassword
        );
        $result['status'] ? $success = $result['message'] : $error = $result['message'];
    }
}

$actionUrl = rex_getUrl(rex_article::getCurrentId());

$fragment = new rex_fragment();
$fragment->setVar('action_url', $actionUrl, false);
$fragment->setVar('firstname', rex_request::post('firstname', 'string', $user->firstname));
$fragment->setVar('lastname', rex_request::post('lastname', 'string', $user->lastname));
$fragment->setVar('email', rex_request::post('email', 'string', $user->email));
$fragment->setVar('error', $error, false);
$fragment->setVar('success', $success, false);
echo $fragment->parse('restricted/' . $theme . '/profile.php');
