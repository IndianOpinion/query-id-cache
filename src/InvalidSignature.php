<?php

declare(strict_types=1);

namespace IndianOpinion\QueryIdCache;

use InvalidArgumentException;

/**
 * Raised by register_signature() when a name or an argument is invalid.
 *
 * It is thrown at registration time so a bad configuration fails loudly
 * during development, never later in the middle of a page render.
 */
final class InvalidSignature extends InvalidArgumentException
{
}
