<?php

declare(strict_types=1);

namespace SpomkyLabs\Pki\ASN1\Type\Tagged;

use SpomkyLabs\Pki\ASN1\Component\Identifier;
use SpomkyLabs\Pki\ASN1\Component\Length;
use SpomkyLabs\Pki\ASN1\Element;
use SpomkyLabs\Pki\ASN1\Exception\DecodeException;
use SpomkyLabs\Pki\ASN1\Feature\ElementBase;
use SpomkyLabs\Pki\ASN1\Type\TaggedType;
use SpomkyLabs\Pki\ASN1\Type\UnspecifiedType;

/**
 * Intermediate class to store tagged DER data.
 *
 * `implicit($tag)` or `explicit()` method is used to decode the actual element, which is only known by the abstract
 * syntax of data structure.
 *
 * May be encoded back to complete DER encoding.
 */
class DERTaggedType extends TaggedType implements ExplicitTagging, ImplicitTagging
{
    /**
     * @param Identifier $identifier Pre-parsed identifier
     * @param string $data DER data
     * @param int $offset Offset to next byte after identifier
     * @param int $valueOffset Offset to content
     * @param int $valueLength Content length
     */
    final private function __construct(
        private readonly Identifier $identifier,
        private readonly string $data,
        private readonly int $offset,
        private readonly int $valueOffset,
        private readonly int $valueLength,
        bool $indefiniteLength
    ) {
        parent::__construct($identifier->intTag(), $indefiniteLength);
    }

    public static function create(
        Identifier $identifier,
        string $data,
        int $offset,
        int $valueOffset,
        int $valueLength,
        bool $indefiniteLength
    ): static {
        return new static($identifier, $data, $offset, $valueOffset, $valueLength, $indefiniteLength);
    }

    public function typeClass(): int
    {
        return $this->_identifier->typeClass();
    }

    public function isConstructed(): bool
    {
        return $this->_identifier->isConstructed();
    }

    public function implicit(int $tag, int $class = Identifier::CLASS_UNIVERSAL): UnspecifiedType
    {
        $identifier = $this->_identifier->withClass($class)
            ->withTag($tag);
        $cls = self::determineImplClass($identifier);
        $idx = $this->_offset;
        /** @var ElementBase $element */
        $element = $cls::decodeFromDER($identifier, $this->_data, $idx);
        return $element->asUnspecified();
    }

    public function explicit(): UnspecifiedType
    {
        $idx = $this->_valueOffset;
        return Element::fromDER($this->_data, $idx)->asUnspecified();
    }

    protected static function decodeFromDER(Identifier $identifier, string $data, int &$offset): ElementBase
    {
        $idx = $offset;
        $length = Length::expectFromDER($data, $idx);
        // offset to inner value
        $valueOffset = $idx;
        if ($length->isIndefinite()) {
            if ($identifier->isPrimitive()) {
                throw new DecodeException('Primitive type with indefinite length is not supported.');
            }
            // EOC consists of two octets.
            $valueLength = $idx - $valueOffset - 2;
        } else {
            $valueLength = $length->intLength();
            $idx += $valueLength;
        }
        // late static binding since ApplicationType and PrivateType extend this class
        $type = static::create($identifier, $data, $offset, $valueOffset, $valueLength, $length->isIndefinite());
        $offset = $idx;
        return $type;
    }

    protected function encodedAsDER(): string
    {
        return mb_substr($this->_data, $this->_valueOffset, $this->_valueLength, '8bit');
    }
}
