<?php

declare(strict_types=1);

namespace KLXM\Restricted\Auth\Api;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Throwable;
use KLXM\Restricted\Auth\PasskeyManager;
use KLXM\Restricted\Auth\CredentialRepository;
use KLXM\Restricted\Auth;
use rex_api_function;
use rex_api_result;
use rex_response;
use rex_session;
use Webauthn\PublicKeyCredentialRequestOptions;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handle processing of the login verification.
 * Call via: index.php?rex-api-call=klxm_restricted_passkey_login_verify
 */
class rex_api_klxm_restricted_passkey_login_verify extends rex_api_function
{
    protected $published = true;

    public function execute(): rex_api_result
    {
        rex_response::cleanOutputBuffers();

        $sessionOptions = rex_session::get('klxm_passkey_login_options');
        if (!$sessionOptions) {
            rex_response::sendJson(['status' => false, 'error' => 'No active login session. Timeout? Please reload.']);
            exit;
        }

        $repo = new CredentialRepository();
        $manager = new PasskeyManager($repo);
        $server = $manager->getServer();

        try {
            // Re-create options from json
            $requestOptions = PublicKeyCredentialRequestOptions::createFromArray($sessionOptions);
            
            // Get JSON payload sent by Javascript
            $clientData = file_get_contents('php://input');

            // Format as PSR7 request
            $psr17Factory = new Psr17Factory();
            $psr17ServerFactory = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
            
            $request = $psr17Factory->createRequest('POST', '')->withBody($psr17Factory->createStream($clientData))->withHeader('Content-Type', 'application/json');

            // Find user_handle associated with the matched credential ID in DB
            $clientDataObj = json_decode($clientData, true);
            $credentialId = $clientDataObj['id'] ?? '';
            $credentialSource = $repo->findOneByCredentialId(base64_decode($credentialId));
            
            if (!$credentialSource) {
                 rex_response::sendJson(['status' => false, 'error' => 'Unknown Passkey']);
                 exit;
            }

            // Perform Assertion verification (Throws if invalid signature)
            $verifiedCredentialSource = $server->loadAndCheckAssertionResponse(
                $clientData,
                $requestOptions,
                null, 
                $request
            );

            // User handle refers to `rex_yform_user.id` mapping (as previously created by the repository on registration)
            $userId = $verifiedCredentialSource->getUserHandle();
            
            $auth = new Auth();
            
            // Log in User by ID
            if ($userId && $auth->loginById((int)$userId)) {
                rex_session::unset('klxm_passkey_login_options');
                rex_response::sendJson(['status' => true, 'message' => 'Login successful', 'redirect' => rex_url::currentBackendPage()]); 
                exit;
            } else {
               rex_response::sendJson(['status' => false, 'error' => 'User not found or inactive']);
               exit;
            }

        } catch (Throwable $e) {
            rex_response::sendJson(['status' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
}
