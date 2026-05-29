<?php

declare(strict_types=1);

namespace SpomkyLabs\Pki\CryptoTypes\Asymmetric\RFC8410\Curve448;

use LogicException;
use function mb_strlen;
use SpomkyLabs\Pki\ASN1\Type\Primitive\OctetString;
use SpomkyLabs\Pki\CryptoTypes\AlgorithmIdentifier\Asymmetric\Ed448AlgorithmIdentifier;
use SpomkyLabs\Pki\CryptoTypes\AlgorithmIdentifier\Feature\AlgorithmIdentifierType;
use SpomkyLabs\Pki\CryptoTypes\Asymmetric\PublicKey;
use SpomkyLabs\Pki\CryptoTypes\Asymmetric\RFC8410\RFC8410PrivateKey;
use UnexpectedValueException;

/**
 * Implements an intermediary class to store Ed448 private key.
 *
 * @see https://tools.ietf.org/html/rfc8410
 */
final class Ed448PrivateKey extends RFC8410PrivateKey
{
    /**
     * @param string $privateKey Private key data
     * @param null|string $publicKey Public key data
     */
    protected function __construct(string $privateKey, ?string $publicKey = null)
    {
        if (mb_strlen($privateKey, '8bit') !== 57) {
            throw new UnexpectedValueException('Ed448 private key must be exactly 57 bytes.');
        }
        if (isset($publicKey) && mb_strlen($publicKey, '8bit') !== 57) {
            throw new UnexpectedValueException('Ed448 public key must be exactly 57 bytes.');
        }
        parent::__construct($privateKey, $publicKey);
    }

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
        return Ed448AlgorithmIdentifier::create();
    }

    public function publicKey(): PublicKey
    {
        if (! $this->hasPublicKey()) {
            throw new LogicException('Public key not set.');
        }
        return Ed448PublicKey::create($this->_publicKeyData);
    }
}
