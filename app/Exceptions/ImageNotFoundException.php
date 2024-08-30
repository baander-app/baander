<?php

namespace App\Exceptions;

use Exception;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ImageNotFoundException extends Exception
{
    public function __construct(string $model, int $code = 0, ?Throwable $previous = null)
    {
        $message = 'Could not find image for model [' . $model . ']';
        parent::__construct($message, $code, $previous);
    }

    public function render()
    {
        return response(__('error.unable_to_locate_image'))
            ->setStatusCode(Response::HTTP_NOT_FOUND);
    }
}
