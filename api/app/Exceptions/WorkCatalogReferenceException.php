<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

final class WorkCatalogReferenceException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
