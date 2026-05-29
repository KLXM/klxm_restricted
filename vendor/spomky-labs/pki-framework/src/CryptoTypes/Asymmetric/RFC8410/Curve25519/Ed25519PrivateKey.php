<?php

declare(strict_types=1);

namespace SpomkyLabs\Pki\CryptoTypes\Asymmetric\RFC8410\Curve25519;

use LogicException;
use SpomkyLabs\Pki\ASN1\Type\Primitive\OctetString;
use SpomkyLabs\Pki\CryptoTypes\AlgorithmIdentifier\Asymmetric\Ed25519AlgorithmIdentifier;
use SpomkyLabs\Pki\CryptoTypes\AlgorithmIdentifier\Feature\AlgorithmIdentifierType;
use SpomkyLabs\Pki\CryptoTypes\Asymmetric\PublicKey;

/**
 * Implements an intermediary object to store Ed25519 private key.
 *
 * @see https://tools.ietf.org/html/rfc8410
 */
final class Ed25519PrivateKey extends Curve25519PrivateKey
{
    public static function create(string $privateKey, ?string $publicKey = null): self
    {
        return new self($privateKey, $publicKey);
    }

    /**
     * Initialize from `CurvePrivateKey` OctetString.
     *
     * @param OctetString $str Private key data wrapped into OctetString
     * @param null|string $publicKey Optional public key data
     */
    public static function fromOctetString(OctetString $str, ?string $publicKey = null): self
    {
        return self::create($str->string(), $publicKey);
    }

    public function algorithmIdentifier(): AlgorithmIdentifierType
    {
        return Ed25519AlgorithmIdentifier::create();
    }

    public function publicKey(): PublicKey
    {
        if (! $this->hasPublicKey()) {
            throw new LogicException('Public key not set.');
        }
        return Ed25519PublicKey::create($this->_publicKeyData);
    }
}
