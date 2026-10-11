<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Constants\HttpStatusCode;
use InvalidArgumentException;
use RuntimeException;

class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        public readonly array $headers = []
    ) {
        if ($status < HttpStatusCode::BAD_REQUEST->value || $status > HttpStatusCode::INTERNAL_SERVER_ERROR->value) {
            throw new InvalidArgumentException('HTTP error status must be between 400 and 599.');
        }

        parent::__construct($message);
    }
}
