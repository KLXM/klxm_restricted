<?php

declare(strict_types=1);

namespace SpomkyLabs\Pki\ASN1\Util;

use function assert;
use Brick\Math\BigInteger;
use function count;
use function is_array;
use function ord;
use OutOfBoundsException;
use RuntimeException;
use SpomkyLabs\Pki\ASN1\Type\Primitive\BitString;

/**
 * Class to handle a bit string as a field of flags.
 * @see \SpomkyLabs\Pki\Test\ASN1\Util\FlagsTest
 */
final class Flags
{
    /**
     * Flag octets.
     */
    private string $flags;

    /**
     * @param BigInteger|int|string $flags Flags
     * @param int $width The number of flags. If width is larger than
     * number of bits in $flags, zeroes are prepended
     * to flag field.
     */
    private function __construct(
        BigInteger|int|string $flags,
        private readonly int $width
    ) {
        if ($width === 0) {
            $this->flags = '';
            return;
        }

        // calculate number of unused bits in last octet
        $lastOctetBits = $width % 8;
        $unusedBits = $lastOctetBits !== 0 ? 8 - $lastOctetBits : 0;
        // mask bits outside bitfield width
        $num = BigInteger::of($flags);
        $mask = BigInteger::of(1)->shiftedLeft($width)->minus(1);
        $num = $num->and($mask);

        // shift towards MSB if needed
        $data = $num->shiftedLeft($unusedBits)
            ->toBytes(false);
        $octets = unpack('C*', $data);
        assert(is_array($octets), new RuntimeException('unpack() failed'));
        $bits = count($octets) * 8;
        // pad with zeroes
        while ($bits < $width) {
            array_unshift($octets, 0);
            $bits += 8;
        }
        $this->flags = pack('C*', ...$octets);
    }

    public static function create(BigInteger|int|string $flags, int $width): self
    {
        return new self($flags, $width);
    }

    /**
     * Initialize from `BitString`.
     */
    public static function fromBitString(BitString $bs, int $width): self
    {
        $numBits = $bs->numBits();
        $data = $bs->string();
        $num = $data === '' ? BigInteger::of(0) : BigInteger::fromBytes($data, false);
        $num = $num->shiftedRight($bs->unusedBits());
        if ($numBits < $width) {
            $num = $num->shiftedLeft($width - $numBits);
        }
        return self::create($num, $width);
    }

    /**
     * Check whether a bit at given index is set.
     *
     * Index 0 is the leftmost bit.
     */
    public function test(int $idx): bool
    {
        if ($idx >= $this->_width) {
            throw new OutOfBoundsException('Index is out of bounds.');
        }
        // octet index
        $oi = (int) floor($idx / 8);
        $byte = $this->flags[$oi];
        // bit index
        $bi = $idx % 8;
        // index 0 is the most significant bit in byte
        $mask = 0x01 << (7 - $bi);
        return (ord($byte) & $mask) > 0;
    }

    /**
     * Get flags as an octet string.
     *
     * Zeroes are appended to the last octet if width is not divisible by 8.
     */
    public function string(): string
    {
        return $this->flags;
    }

    /**
     * Get flags as a base 10 integer.
     *
     * @return string Integer as a string
     */
    public function number(): string
    {
        $num = $this->flags === '' ? BigInteger::of(0) : BigInteger::fromBytes($this->flags, false);
        $lastOctetBits = $this->_width % 8;
        $unusedBits = $lastOctetBits !== 0 ? 8 - $lastOctetBits : 0;
        $num = $num->shiftedRight($unusedBits);
        return $num->toBase(10);
    }

    /**
     * Get flags as an integer.
     */
    public function intNumber(): int
    {
        $num = BigInt::create($this->number());
        return $num->toInt();
    }

    /**
     * Get flags as a `BitString` object.
     *
     * Unused bits are set accordingly. Trailing zeroes are not stripped.
     */
    public function bitString(): BitString
    {
        $lastOctetBits = $this->_width % 8;
        $unusedBits = $lastOctetBits !== 0 ? 8 - $lastOctetBits : 0;
        return BitString::create($this->flags, $unusedBits);
    }
}
