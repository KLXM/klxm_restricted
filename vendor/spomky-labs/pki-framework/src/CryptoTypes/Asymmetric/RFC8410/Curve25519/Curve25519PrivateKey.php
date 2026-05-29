<?php

declare(strict_types=1);

namespace SpomkyLabs\Pki\CryptoTypes\Asymmetric\RFC8410\Curve25519;

use function mb_strlen;
use SpomkyLabs\Pki\CryptoTypes\Asymmetric\RFC8410\RFC8410PrivateKey;
use UnexpectedValueException;

/**
 * Implements an intermediary object to store a private key using Curve25519.
 *
 * @see https://tools.ietf.org/html/rfc8410
 */
abstract class Curve25519PrivateKey extends RFC8410PrivateKey
{
    /**
     * @param string $privateKey Private key data
     * @param null|string $publicKey Public key data
     */
    protected function __construct(string $privateKey, ?string $publicKey = null)
    {
        if (mb_strlen($privateKey, '8bit') !== 32) {
            throw new UnexpectedValueException('Curve25519 private key must be exactly 32 bytes.');
        }
        if (isset($publicKey) && mb_strlen($publicKey, '8bit') !== 32) {
            throw new UnexpectedValueException('Curve25519 public key must be exactly 32 bytes.');
        }
        parent::__construct($privateKey, $publicKey);
    }
}
