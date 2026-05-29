<?php

declare(strict_types=1);

namespace KLXM\Restricted\Auth;

use rex;
use rex_addon;
use rex_response;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\Server;
use Webauthn\PublicKeyCredentialSourceRepository;

class PasskeyManager
{
    private Server $server;

    public function __construct(PublicKeyCredentialSourceRepository $repository)
    {
        // Addon URL as RP ID for Passkey Server Configuration
        $domain = parse_url(rex::getServer(), PHP_URL_HOST) ?? 'localhost';
        if ($domain === null || $domain === '') {
            $domain = 'localhost';
        }

        $rpEntity = new PublicKeyCredentialRpEntity(
            rex::getServerName(), // Relying Party Name
            $domain,               // Relying Party ID
            null                   // Icon (optional)
        );

        // WebAuthn Server Initialization
        // It validates requests and generates options for the client
        $this->server = new Server(
            $rpEntity,
            $repository
        );
    }

    public function getServer(): Server
    {
        return $this->server;
    }
}
