<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Doctrine\DedicatedConnectionFactory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

/** Removes only the browser registrations explicitly owned by a preview test run. */
final class BrowserDeviceFixture
{
    public function __construct(private readonly Connection $connection) {}

    /** @param array<array-key, mixed> $deviceIds */
    public function cleanup(array $deviceIds): int
    {
        if (!array_is_list($deviceIds)) {
            throw new InvalidArgumentException('Browser device ownership must be a list of UUIDs.');
        }
        $validated = [];
        foreach ($deviceIds as $deviceId) {
            if (!is_string($deviceId) || !Uuid::isValid($deviceId)) {
                throw new InvalidArgumentException('Every owned browser device token must be a UUID.');
            }
            $validated[] = Uuid::fromString($deviceId)->toRfc4122();
        }
        $validated = array_values(array_unique($validated));
        if ($validated === []) {
            return 0;
        }

        return $this->connection->transactional(static function (Connection $connection) use ($validated): int {
            $owner = $connection->fetchOne('SELECT id FROM users WHERE email = ?', ['admin@baander.test']);
            if (!is_string($owner) || $owner === '') {
                throw new RuntimeException('The seeded administrator is missing from the browser test target.');
            }
            $deleted = 0;
            foreach (array_chunk($validated, 512) as $batch) {
                $deleted += $connection->executeStatement(
                    'DELETE FROM devices WHERE user_id = :owner AND device_id IN (:devices)',
                    ['owner' => $owner, 'devices' => $batch],
                    ['devices' => ArrayParameterType::STRING],
                );
            }
            return $deleted;
        });
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (getenv('BAANDER_BROWSER_DEVICE_CLEANUP') !== '1') {
            throw new RuntimeException('Browser device cleanup requires BAANDER_BROWSER_DEVICE_CLEANUP=1.');
        }
        $command = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($command) || !isset($command['deviceIds']) || !is_array($command['deviceIds'])) {
            throw new InvalidArgumentException('Expected a JSON object containing a deviceIds list.');
        }
        (new Dotenv())->bootEnv(dirname(__DIR__, 3) . '/.env');
        $url = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? getenv('DATABASE_URL');
        if (!is_string($url) || $url === '') {
            throw new RuntimeException('Browser device cleanup requires the managed preview database.');
        }
        $connection = DedicatedConnectionFactory::fromUrl($url);
        try {
            $deleted = new BrowserDeviceFixture($connection)->cleanup($command['deviceIds']);
            fwrite(STDOUT, json_encode(['deleted' => $deleted], JSON_THROW_ON_ERROR) . "\n");
        } finally {
            $connection->close();
        }
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
