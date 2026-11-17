<?php

declare(strict_types=1);

namespace App\Party\Application\Exception;

use App\Shared\Application\Exception\NotFoundException;

/**
 * The party does not exist, or the caller may not learn that it does.
 *
 * The message is the one a route gives for any missing resource, and it is not translated,
 * so HTTP answers a refused caller exactly as it answers for a party that does not exist.
 */
final class PartyNotFoundException extends NotFoundException
{
    public function __construct()
    {
        parent::__construct('Resource not found.');
    }
}
