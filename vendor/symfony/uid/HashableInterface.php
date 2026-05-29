<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Uid;

use Ds\Hashable;

if (interface_exists(Hashable::class)) {
    /**
     * @internal
     */
    interface HashableInterface extends Hashable
    {
        public function hash(): string;
    }
} else {
    /**
     * @internal
     */
    interface HashableInterface
    {
        public function equals(mixed $other): bool;

        public function hash(): string;
    }
}
