<?php

declare(strict_types=1);

namespace KLXM\Restricted\Auth\Api;

use Nyholm\Psr7\Factory\Psr17Factory;
use Throwable;
use KLXM\Restricted\Auth\PasskeyManager;
use KLXM\Restricted\Auth\CredentialRepository;
use KLXM\Restricted\Auth;
use rex_api_function;
use rex_api_result;
use rex_response;
use rex_session;
use Webauthn\PublicKeyCredentialCreationOptions;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handle processing of the registration result.
 * Call via: index.php?rex-api-call=klxm_restricted_passkey_register_verify
 */
class rex_api_klxm_restricted_passkey_register_verify extends rex_api_function
{
    protected $published = true;

    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        $auth = new Auth();
        if (!$auth->isLoggedIn()) {
            rex_response::sendJson(['status' => false, 'error' => 'Not authenticated']);
            exit;
        }

        $sessionOptions = rex_session::get('klxm_passkey_creation_options');
        if (!$sessionOptions) {
            rex_response::sendJson(['status' => false, 'error' => 'No active registration session']);
            exit;
        }

        $repo = new CredentialRepository();
        $manager = new PasskeyManager($repo);
        $server = $manager->getServer();

        try {
            // Re-create options from json
            $creationOptions = PublicKeyCredentialCreationOptions::createFromArray($sessionOptions);
            
            // Get JSON payload sent by Javascript
            $clientData = file_get_contents('php://input');

            // Convert to PSR-7 Request (Library requires this interface for parsing)
            // For simplicity, we mock a basic PSR-7 Request here or parse manually since Redaxo doesn't use PSR-7 natively
            $psr17Factory = new Psr17Factory();
            $stream = $psr17Factory->createStream($clientData);
            $request = $psr17Factory->createRequest('POST', '')->withBody($stream)->withHeader('Content-Type', 'application/json');


            // Verify Attestation (Registers it in DB via repository)
            $credentialSource = $server->loadAndCheckAttestationResponse(
                $clientData,
                $creationOptions,
                $request
            );

            rex_session::unset('klxm_passkey_creation_options');
            rex_response::sendJson(['status' => true, 'message' => 'Passkey erfolgreich hinzugefügt']);
            exit;

        } catch (Throwable $e) {
            rex_response::sendJson(['status' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
}
