<?php

namespace App\Services\Channels;

use RuntimeException;

/** A failure that might succeed on retry (network blip, provider rate limit, timeout) — FSD 5's exponential backoff applies. */
class TransientSendException extends RuntimeException
{
}
