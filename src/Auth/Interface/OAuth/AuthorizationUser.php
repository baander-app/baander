<?php

declare(strict_types=1);

namespace App\Auth\Interface\OAuth;

use App\Shared\Domain\Model\Uuid;
use League\OAuth2\Server\Entities\UserEntityInterface;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** The authenticated identity supplied to League's authorization request. */
#[Exclude]
final readonly class AuthorizationUser implements UserEntityInterface
{
    public function __construct(private Uuid $id)
    {
    }

    public function getIdentifier(): string
    {
        $identifier = $this->id->toString();
        if ($identifier === '') {
            throw new LogicException('An authorization user requires an identifier.');
        }

        return $identifier;
    }
}
