<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Application\Command\OAuth\RevokeClientCommand;
use App\Auth\Application\CommandHandler\OAuth\RevokeClientHandler;
use App\Auth\Application\DTO\TokenResponseDTO;
use App\Auth\Application\Port\JwtGeneratorInterface;
use App\Auth\Application\ScopeAllowlist;
use App\Auth\Application\Service\ClientRevoker;
use App\Auth\Application\Service\TokenPairIssuer;
use App\Auth\Domain\Model\OAuth\AccessToken;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Model\UserState;
use App\Auth\Infrastructure\Repository\OAuth\AccessTokenRepository;
use App\Auth\Infrastructure\Repository\OAuth\ClientRepository;
use App\Auth\Infrastructure\Repository\OAuth\RefreshTokenRepository;
use App\Auth\Infrastructure\Repository\OAuth\TokenMetadataRepository;
use App\Shared\Application\Port\TransactionPortInterface;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use App\Shared\Infrastructure\Doctrine\Type\CustomTypesRegistrar;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * Production OAuth issuance and client revocation over one disposable schema, shared by
 * OAuthClientRevocationRaceTest and the child process it runs (oauth-revocation-race.php).
 */
final class OAuthRevocationRace
{
    public const string USER_EMAIL = 'race@baander.app';

    /** A connection whose session uses the fixture schema; extension types stay in public. */
    public static function connect(string $schema, ?string $applicationName = null): Connection
    {
        $url = getenv('OUTBOX_TEST_DATABASE_URL');
        if ($url === false || $url === '') {
            throw new \LogicException('OUTBOX_TEST_DATABASE_URL must name a disposable PostgreSQL database.');
        }
        $connection = DriverManager::getConnection((new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url));
        $connection->executeStatement('SET search_path TO ' . $schema . ', public');
        if ($applicationName !== null) {
            $connection->executeQuery("SELECT set_config('application_name', :name, false)", ['name' => $applicationName])->free();
        }

        return $connection;
    }

    public static function entityManager(Connection $connection): EntityManager
    {
        CustomTypesRegistrar::register();
        $config = ORMSetup::createAttributeMetadataConfig([
            dirname(__DIR__, 3) . '/src/Auth/Infrastructure/Doctrine/Entity',
        ], isDevMode: true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->enableNativeLazyObjects(true);

        return new EntityManager($connection, $config);
    }

    /** The user who owns the client; its row is written by the test with the same identity. */
    public static function user(Uuid $id, PublicId $publicId): User
    {
        $now = new \DateTimeImmutable();

        return User::reconstitute(new UserState($id, $publicId, 'Race', self::USER_EMAIL, 'hashed', null, $now, $now));
    }

    /**
     * @param (callable(): void)|null $whileLocked Runs inside the issuing transaction, after the client lock
     */
    public static function issue(EntityManager $manager, Uuid $clientId, User $user, ?callable $whileLocked = null): TokenResponseDTO
    {
        $encoder = new JsonEncoder();
        $clients = new ClientRepository($manager, $encoder);
        $client = $clients->findClientByUuid($clientId) ?? throw new \LogicException('The race client is missing.');
        $issuer = new TokenPairIssuer(
            new AccessTokenRepository($manager, $encoder),
            new RefreshTokenRepository($manager, $encoder),
            new ScopeAllowlist(['profile']),
            $manager,
            new class () implements JwtGeneratorInterface {
                public function generate(AccessToken $accessToken, ?string $dpopJkt = null): string
                {
                    return 'header.payload.signature';
                }
            },
            new TokenMetadataRepository($manager),
            $clients,
            3600,
            86400,
        );

        return $issuer->issue($client, $user, ['profile'], 'race-proof-key-thumbprint', persistWithTokens: $whileLocked);
    }

    /**
     * @param (callable(): void)|null $beforeCommit Runs inside the revoking transaction, after all of its writes
     */
    public static function revoke(EntityManager $manager, Uuid $userId, PublicId $clientPublicId, ?callable $beforeCommit = null): void
    {
        $encoder = new JsonEncoder();
        $clients = new ClientRepository($manager, $encoder);
        $handler = new RevokeClientHandler($clients, new ClientRevoker(
            $clients,
            new AccessTokenRepository($manager, $encoder),
            new RefreshTokenRepository($manager, $encoder),
            new class ($manager, $beforeCommit) implements TransactionPortInterface {
                /** @param (callable(): void)|null $beforeCommit */
                public function __construct(private EntityManager $manager, private $beforeCommit)
                {
                }

                public function run(callable $operation): mixed
                {
                    // DoctrineTransaction's boundary without its Swoole service pool handling.
                    return $this->manager->getConnection()->transactional(function () use ($operation): mixed {
                        $result = $operation();
                        $this->manager->flush();
                        if ($this->beforeCommit !== null) {
                            ($this->beforeCommit)();
                        }

                        return $result;
                    });
                }
            },
        ));

        $handler(new RevokeClientCommand($userId, $clientPublicId));
    }
}
