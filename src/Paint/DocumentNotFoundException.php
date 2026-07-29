<?php

declare(strict_types=1);

namespace App\Paint;

use RuntimeException;

/**
 * Signals that a requested Paint document does not exist or is deleted.
 */
final class DocumentNotFoundException extends RuntimeException
{
}
