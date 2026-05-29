<?php

declare(strict_types=1);

namespace KLXM\Restricted\Auth\Api;

use KLXM\Restricted\Auth\PasskeyManager;
use KLXM\Restricted\Auth\CredentialRepository;
use KLXM\Restricted\Auth;
use rex_api_function;
use rex_api_result;
use rex_response;
use rex_session;

class rex_api_klxm_restricted_passkey_login_options extends rex_api_function
{
    protected $published = true;

    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        // Optional: Check if a username is provided to restrict login to that user's credentials
        // $username = rex_request('username', 'string', '');
        
        $repo = new CredentialRepository();
        $manager = new PasskeyManager($repo);
        
        $optionsArray = $manager->generateLoginOptions();

        // 60 seconds TTL equivalent timeout handling is typically done in JS side via navigator.credentials,
        // but it's important to save state linked to this user's session ID (even anonymous sessions have a session ID)
        rex_session::set('klxm_passkey_login_options', $optionsArray);

        rex_response::sendJson($optionsArray);
        exit;
    }
}
