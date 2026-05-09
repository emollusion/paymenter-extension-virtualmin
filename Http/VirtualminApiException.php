<?php

namespace Paymenter\Extensions\Servers\Virtualmin\Http;

use RuntimeException;

/**
 * Thrown when the Virtualmin Remote API returns an error,
 * a non-JSON response, or a connection failure.
 */
class VirtualminApiException extends RuntimeException
{
}
