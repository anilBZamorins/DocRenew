<?php

namespace App\Services\Channels;

use RuntimeException;

/** A failure retrying won't fix (no contact info on file, channel not configured for this tenant) — fails immediately, no backoff wasted. */
class PermanentSendException extends RuntimeException
{
}
