<?php

declare(strict_types=1);

namespace App\Auth\Application\Service;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;

/**
 * Finds the client an administrator's change targets: a device, public or confidential
 * client. The first-party client and personal access clients are not managed here.
 */
final class AdministeredClients
{
    /**
     * @throws NotFoundException when no client has the ID
     * @throws ConflictException with reason protected_client for a first-party or personal access client
     */
    public static function find(ClientRepositoryInterface $clients, PublicId $clientId): Client
    {
        $client = $clients->findClientByPublicId($clientId)
            ?? throw new NotFoundException(sprintf('No OAuth client has the ID "%s".', $clientId->toString()));

        return $client->getType()->isAdministered()
            ? $client
            : throw self::conflict('protected_client', 'The first-party and personal access clients cannot be changed here.');
    }

    /** The client's state does not allow the change; `reason` names the state. */
    public static function conflict(string $reason, string $message): ConflictException
    {
        return new ConflictException($message, ['reason' => $reason]);
    }
}
