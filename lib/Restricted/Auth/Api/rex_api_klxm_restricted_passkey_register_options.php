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
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Handle initial registration step.
 * Call via: index.php?rex-api-call=klxm_restricted_passkey_register_options
 */
class rex_api_klxm_restricted_passkey_register_options extends rex_api_function
{
    protected $published = true; // Frontend accessible

    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        // 1. You must be logged in to register a passkey
        $auth = new Auth();
        if (!$auth->isLoggedIn()) {
            rex_response::sendJson(['status' => false, 'error' => 'Not authenticated']);
            exit;
        }

        $user = $auth->getUser();

        $repo = new CredentialRepository();
        $manager = new PasskeyManager($repo);
        $server = $manager->getServer();

        // 2. Create a WebAuthn User Entity
        $userEntity = new PublicKeyCredentialUserEntity(
            $user->email,                               // Name
            (string) $user->id,                         // User ID
            $user->firstname . ' ' . $user->lastname    // Display Name
        );

        // 3. Generate Creation Options
        $creationOptions = $server->generatePublicKeyCredentialCreationOptions(
            $userEntity,
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE
        );

        // 4. Save options to session for the next step 
        // We must verify the response against exactly these options
        rex_session::set('klxm_passkey_creation_options', $creationOptions->jsonSerialize());

        // 5. Send options to the browser (JS)
        rex_response::sendJson($creationOptions->jsonSerialize());
        exit;
    }
}
