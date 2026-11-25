<?php

declare(strict_types=1);

// Request identity on production app:serve; the runner owns the disposable services and credentials.
// Every worker must answer a request without credentials as a freshly started server does, whoever it
// served before, and answer each signed-in user with that user's own profile.
use App\Auth\Domain\Model\OAuth\SignInGrant;
use App\Auth\Infrastructure\Doctrine\Entity\UserEntity;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Infrastructure\Swoole\Control\SocketServerControlClient;
use App\Tests\Fixtures\Auth\FirstPartyOAuthClient;
use App\Tests\Fixtures\WebRuntime\HeldConnection;
use App\Tests\Fixtures\WebRuntime\StreamClient;
use App\Tests\Fixtures\WebRuntime\WorkerRequestCounts;
use Doctrine\ORM\EntityManagerInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

const IDENTITY_SERVER = 'http://127.0.0.1:9501';
const IDENTITY_USERS = '/api/admin/users';
const IDENTITY_PROFILE = '/api/auth/me';
// Fixed dispatch maps a connection to a worker by its descriptor in the server. Connections that
// are open together have different descriptors, and consecutive ones as long as none closes.
// Three per worker leave room for a descriptor in other use.
const IDENTITY_CONNECTIONS_PER_WORKER = 3;
const IDENTITY_CONCURRENT_ROUNDS = 5;

function identityCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Opens $count connections and adds them to $held, which keeps them open until the process ends:
 * a descriptor the server is still releasing would leave a gap in those of the next connections.
 *
 * @param list<HeldConnection> $held
 *
 * @return list<HeldConnection>
 */
function identityConnections(int $count, array &$held): array
{
    $connections = array_map(static fn (): HeldConnection => new HeldConnection(), range(1, $count));
    array_push($held, ...$connections);

    return $connections;
}

/** @return list<string> the header lines of a request by $user, with a fresh proof */
function identityHeaders(StreamClient $user, string $method, string $path): array
{
    return $user->proofHeaders($method, IDENTITY_SERVER . $path);
}

/**
 * @param array{status: int, body: string} $answer
 *
 * @return string|null the public ID of the profile in the answer
 */
function identityProfile(array $answer): ?string
{
    $publicId = $answer['status'] === 200 ? (json_decode($answer['body'], true, flags: JSON_THROW_ON_ERROR)['data']['publicId'] ?? null) : null;

    return is_string($publicId) ? $publicId : null;
}

$kernel = new App\Kernel('prod', false);
$kernel->boot();
$container = $kernel->getContainer();
$manager = $container->get('doctrine')->getManager();
identityCheck($manager instanceof EntityManagerInterface, 'No entity manager.');
$control = new SocketServerControlClient((string) $container->getParameter('app.server_control.socket_path'));
$privateKey = (string) file_get_contents('config/secrets/oauth/private.key');
$firstParty = FirstPartyOAuthClient::entity($container, $manager);

/**
 * A verified user with a session of Baander's own client, as a password sign-in gives.
 *
 * @param list<string> $roles
 *
 * @return array{name: string, id: string, session: StreamClient}
 */
$signIn = static function (string $name, array $roles) use ($manager, $privateKey, $firstParty): array {
    $email = 'identity-' . bin2hex(random_bytes(6)) . '@baander.app';
    $user = new UserEntity(new PublicId(), 'Request identity ' . $name, $email, 'test-only-unused-password', roles: $roles);
    $user->markEmailAsVerified();
    $manager->persist($user);
    $manager->flush();

    return [
        'name' => $name,
        'id' => $user->getPublicId()->toString(),
        'session' => new StreamClient($manager, $email, $privateKey, IDENTITY_SERVER . '/api/stream/track', SignInGrant::Password, client: $firstParty),
    ];
};
$admin = $signIn('admin', ['ROLE_USER', 'ROLE_ADMIN']);
$member = $signIn('member', ['ROLE_USER']);
$names = [$admin['id'] => 'the admin', $member['id'] => 'the member'];

$workers = count(WorkerRequestCounts::read($control)->workers);
$perSide = IDENTITY_CONNECTIONS_PER_WORKER * $workers;
$held = [];
$failures = [];

/**
 * The signed-in side's requests, then both paths without credentials on fresh connections,
 * with the workers' request counts read before, between and after.
 *
 * @param callable(): void $signedInSide
 */
$thenWithoutCredentials = static function (string $who, callable $signedInSide) use ($control, $perSide, $names, &$held, &$failures): void {
    $before = WorkerRequestCounts::read($control);
    $signedInSide();
    $between = WorkerRequestCounts::read($control);
    $answers = [];
    foreach (identityConnections($perSide, $held) as $connection) {
        $users = $connection->request('GET', IDENTITY_USERS);
        $profile = $connection->request('GET', IDENTITY_PROFILE);
        $named = identityProfile($profile);
        $answer = sprintf(
            '%d on %s and %d on %s%s',
            $users['status'],
            IDENTITY_USERS,
            $profile['status'],
            IDENTITY_PROFILE,
            $named === null ? '' : ' with the profile of ' . ($names[$named] ?? 'another user'),
        );
        $answers[$answer] = ($answers[$answer] ?? 0) + 1;
    }
    $after = WorkerRequestCounts::read($control);

    $counts = [];
    foreach ([$before, $between, $after] as $reading) {
        foreach ($reading->workers as $id => $worker) {
            $counts[$id][] = $worker['received'];
        }
    }
    $perWorker = array_map(static fn (int $id, array $received): string => sprintf('worker %d: %s', $id, implode(', ', $received)), array_keys($counts), $counts);
    echo sprintf("Request identity, after %s: requests per worker before, between the sides and after: %s.\n", $who, implode('; ', $perWorker));
    $unproven = [
        ...array_map(static fn (string $reason): string => 'signed-in side: ' . $reason, $before->workersNotShownToHaveServed($between)),
        ...array_map(static fn (string $reason): string => 'side without credentials: ' . $reason, $between->workersNotShownToHaveServed($after)),
    ];
    if ($unproven !== []) {
        $failures[] = sprintf('After %s the drill is inconclusive, because not every worker is shown to have served both sides (%s).', $who, implode('; ', $unproven));
    }
    $expected = sprintf('401 on %s and 404 on %s', IDENTITY_USERS, IDENTITY_PROFILE);
    if (array_keys($answers) !== [$expected]) {
        $summary = [];
        foreach ($answers as $answer => $count) {
            $summary[] = sprintf('%d of %d connections answered %s', $count, $perSide, $answer);
        }
        $failures[] = sprintf('After %s, requests without credentials were not answered as on a fresh server (%s): %s.', $who, $expected, implode('; ', $summary));

        return;
    }
    echo sprintf("Request identity, after %s: %d connections without credentials answered %s.\n", $who, $perSide, $expected);
};

$adminConnections = identityConnections($perSide, $held);
$thenWithoutCredentials('the admin', static function () use ($adminConnections, $admin): void {
    foreach ($adminConnections as $connection) {
        $users = $connection->request('GET', IDENTITY_USERS, identityHeaders($admin['session'], 'GET', IDENTITY_USERS));
        identityCheck($users['status'] === 200, 'The admin could not list users: ' . $users['status']);
        $profile = $connection->request('GET', IDENTITY_PROFILE, identityHeaders($admin['session'], 'GET', IDENTITY_PROFILE));
        identityCheck(identityProfile($profile) === $admin['id'], 'The admin did not read their own profile: ' . $profile['status']);
    }
});

$memberConnections = identityConnections($perSide, $held);
$thenWithoutCredentials('the member', static function () use ($memberConnections, $member): void {
    foreach ($memberConnections as $connection) {
        $users = $connection->request('GET', IDENTITY_USERS, identityHeaders($member['session'], 'GET', IDENTITY_USERS));
        identityCheck($users['status'] === 403, 'The member was not refused the user list: ' . $users['status']);
        $profile = $connection->request('GET', IDENTITY_PROFILE, identityHeaders($member['session'], 'GET', IDENTITY_PROFILE));
        identityCheck(identityProfile($profile) === $member['id'], 'The member did not read their own profile: ' . $profile['status']);
    }
});

// Each set of connections was shown above to reach every worker, so the two users share every
// worker. All requests of a round are written before any answer is read, so each worker holds
// requests of both users at once. Nothing here makes a worker switch between two of them: the
// workers hook only curl and process calls, and the profile route makes neither. The forced
// switch is RequestIdentityInterleaveTest's.
$own = 0;
$foreign = [];
for ($round = 0; $round < IDENTITY_CONCURRENT_ROUNDS; ++$round) {
    $inFlight = [];
    foreach ([[$admin, $adminConnections], [$member, $memberConnections]] as [$user, $connections]) {
        foreach ($connections as $connection) {
            $inFlight[] = [$user, $connection, identityHeaders($user['session'], 'GET', IDENTITY_PROFILE)];
        }
    }
    foreach ($inFlight as [, $connection, $headers]) {
        $connection->send('GET', IDENTITY_PROFILE, $headers);
    }
    foreach ($inFlight as [$user, $connection]) {
        $profile = $connection->receive();
        $named = identityProfile($profile);
        if ($named === $user['id']) {
            ++$own;
            continue;
        }
        $answer = sprintf('the %s received %s', $user['name'], $named === null ? 'status ' . $profile['status'] : 'the profile of ' . ($names[$named] ?? 'another user'));
        $foreign[$answer] = ($foreign[$answer] ?? 0) + 1;
    }
}
if ($foreign === []) {
    echo sprintf("Request identity: %d concurrent profile requests of two users on shared workers each received their own profile.\n", $own);
} else {
    $summary = [];
    foreach ($foreign as $answer => $count) {
        $summary[] = sprintf('%d times %s', $count, $answer);
    }
    $failures[] = sprintf('Of %d concurrent profile requests of two users on shared workers, %d did not receive their own profile: %s.', $own + array_sum($foreign), array_sum($foreign), implode('; ', $summary));
}

$thenWithoutCredentials('the admin signed out', static function () use ($adminConnections, $admin): void {
    foreach ($adminConnections as $connection) {
        $profile = $connection->request('GET', IDENTITY_PROFILE, identityHeaders($admin['session'], 'GET', IDENTITY_PROFILE));
        identityCheck(identityProfile($profile) === $admin['id'], 'The admin did not read their own profile: ' . $profile['status']);
    }
    $logout = $adminConnections[0]->request('POST', '/api/auth/logout', identityHeaders($admin['session'], 'POST', '/api/auth/logout'));
    identityCheck($logout['status'] === 200, 'The admin could not sign out: ' . $logout['status']);
    $revoked = $adminConnections[0]->request('GET', IDENTITY_PROFILE, identityHeaders($admin['session'], 'GET', IDENTITY_PROFILE));
    identityCheck($revoked['status'] === 401, 'The signed-out session was not refused: ' . $revoked['status']);
});

$kernel->shutdown();
if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo sprintf("Request identity: %d workers, %d connections on each side of each comparison.\n", $workers, $perSide);
