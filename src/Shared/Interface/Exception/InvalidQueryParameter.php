<?php

declare(strict_types=1);

namespace App\Shared\Interface\Exception;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class InvalidQueryParameter extends BadRequestHttpException
{
    public function __construct(public readonly string $parameter, string $message)
    {
        parent::__construct($message);
    }
}
