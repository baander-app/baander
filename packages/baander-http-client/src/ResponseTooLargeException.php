<?php

declare(strict_types=1);

namespace Baander\HttpClient;

final class ResponseTooLargeException extends \RuntimeException
{
    public function __construct(
        public readonly int $maxBytes,
    ) {
        parent::__construct(sprintf('The provider response is larger than %d bytes.', $maxBytes));
    }
}
