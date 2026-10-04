<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class InvalidPreferenceRequestBody extends HttpException
{
}
