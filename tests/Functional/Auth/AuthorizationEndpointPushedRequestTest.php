<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Domain\Model\OAuth\Client;
use App\Auth\Domain\Model\User;
use App\Auth\Domain\Repository\OAuth\ClientRepositoryInterface;
use App\Tests\Fixtures\Auth\SignedDpopProof;
use App\Tests\Functional\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The consent page's lookup and decision on a pushed authorization request (RFC 9126 section 4),
 * against PostgreSQL. The browser carries only client_id and request_uri; the pushed request
 * supplies every other parameter, and the decision uses it up.
 */
final class AuthorizationEndpointPushedRequestTest extends TestCase
{
    private const string REDIRECT = 'https://player.baander.app/callback';
    private const string OTHER_REDIRECT = 'https://player.baander.app/other';
    // RFC 7636 appendix B.
    private const string CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    private User $user;
    private string $jkt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createTestUser();
        $this->jkt = (new SignedDpopProof())->thumbprint();
    }

    public function testALookupWithoutARequestUriIsShownWithoutARedirect(): void
    {
        $response = $this->authorize('GET', [
            'response_type' => 'code', 'client_id' => $this->publicClient(), 'redirect_uri' => self::REDIRECT,
            'code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256',
        ]);

        $error = $this->error($response);
        self::assertSame('invalid_request', $error['error']);
        self::assertArrayNotHasKey('redirect_uri', $error);
        self::assertSame(0, $this->codeCount());
    }

    public function testARequestPushedSixMinutesAgoHasExpired(): void
    {
        $clientId = $this->publicClient();
        $requestUri = $this->push($clientId);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE oauth_pushed_authorization_requests SET created_at = now() - interval '6 minutes', expires_at = now() - interval '1 minute' WHERE request_id = ?",
            [self::requestId($requestUri)],
        );

        $this->assertUnusableRequest($this->authorize('GET', ['client_id' => $clientId, 'request_uri' => $requestUri]));
        $this->assertUnusableRequest($this->authorize('POST', ['client_id' => $clientId, 'request_uri' => $requestUri, 'decision' => 'approve']));
        self::assertSame(0, $this->codeCount());
    }

    public function testAnApprovedRequestCannotBeUsedAgain(): void
    {
        $clientId = $this->publicClient();
        $requestUri = $this->push($clientId);
        $this->decide($clientId, $requestUri, 'approve');

        $this->assertUnusableRequest($this->authorize('GET', ['client_id' => $clientId, 'request_uri' => $requestUri]));
        $this->assertUnusableRequest($this->authorize('POST', ['client_id' => $clientId, 'request_uri' => $requestUri, 'decision' => 'approve']));
        self::assertSame(1, $this->codeCount());
    }

    public function testTwoLookupsThenOneApprovalIssueExactlyOneCode(): void
    {
        $clientId = $this->publicClient();
        $requestUri = $this->push($clientId);

        foreach ([1, 2] as $lookup) {
            $response = $this->authorize('GET', ['client_id' => $clientId, 'request_uri' => $requestUri]);
            self::assertSame(200, $response->getStatusCode(), sprintf('Lookup %d: %s', $lookup, $response->getContent()));
        }
        $redirect = $this->decide($clientId, $requestUri, 'approve');

        self::assertArrayHasKey('code', $redirect);
        self::assertSame(1, $this->codeCount());
    }

    public function testParametersInTheBrowserUrlBesideTheRequestUriHaveNoEffect(): void
    {
        $clientId = $this->publicClient();
        $requestUri = $this->push($clientId);
        $tampered = [
            'client_id' => $clientId, 'request_uri' => $requestUri,
            'redirect_uri' => self::OTHER_REDIRECT, 'scope' => 'playlist profile', 'state' => 'browser-state',
            'code_challenge' => str_repeat('b', 43), 'response_type' => 'token',
        ];

        $lookup = $this->authorize('GET', $tampered);
        self::assertSame(200, $lookup->getStatusCode(), (string) $lookup->getContent());
        $details = json_decode((string) $lookup->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(self::REDIRECT, $details['redirect_uri']);
        self::assertSame(['library'], $details['scopes']);

        $redirect = $this->decide($clientId, $requestUri, 'approve', $tampered);
        self::assertSame('pushed-state', $redirect['state']);
        $code = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT redirect_uri, code_challenge, scopes FROM oauth_auth_codes WHERE code_id = ?',
            [hash('sha256', $redirect['code'])],
        );
        self::assertIsArray($code);
        self::assertSame(self::REDIRECT, $code['redirect_uri']);
        self::assertSame(self::CHALLENGE, $code['code_challenge']);
        self::assertSame(['library'], json_decode((string) $code['scopes'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testAnotherClientsIdWithTheRequestUriIsRejected(): void
    {
        $requestUri = $this->push($this->publicClient());
        $other = $this->publicClient('Other player');

        $this->assertUnusableRequest($this->authorize('GET', ['client_id' => $other, 'request_uri' => $requestUri]));
        $this->assertUnusableRequest($this->authorize('POST', ['client_id' => $other, 'request_uri' => $requestUri, 'decision' => 'approve']));
        self::assertSame(0, $this->codeCount());
    }

    public function testAMalformedRequestUriIsRejected(): void
    {
        $clientId = $this->publicClient();

        $this->assertUnusableRequest($this->authorize('GET', ['client_id' => $clientId, 'request_uri' => 'urn:example:request_uri:' . str_repeat('a', 80)]));
        $this->assertUnusableRequest($this->authorize('GET', ['client_id' => $clientId, 'request_uri' => 'urn:ietf:params:oauth:request_uri:' . str_repeat('a', 80)]));
    }

    public function testDenialUsesTheRequestUpAndRedirectsWithThePushedStateAndIssuer(): void
    {
        $clientId = $this->publicClient();
        $requestUri = $this->push($clientId);

        $redirect = $this->decide($clientId, $requestUri, 'deny');

        self::assertSame('access_denied', $redirect['error']);
        self::assertSame('pushed-state', $redirect['state']);
        self::assertSame(static::getContainer()->getParameter('auth.oauth.issuer'), $redirect['iss']);
        self::assertArrayNotHasKey('code', $redirect);
        self::assertTrue($this->isConsumed($requestUri));
    }

    public function testADisabledUserIsRedirectedWithAccessDeniedAndTheRequestStaysPending(): void
    {
        $clientId = $this->publicClient();
        $requestUri = $this->push($clientId);
        $this->user->disable();
        $this->userRepository->save($this->user);

        $response = $this->authorize('POST', ['client_id' => $clientId, 'request_uri' => $requestUri, 'decision' => 'approve']);

        $redirect = $this->redirectOf($this->error($response));
        self::assertSame('access_denied', $redirect['error']);
        self::assertSame('pushed-state', $redirect['state']);
        self::assertFalse($this->isConsumed($requestUri));
        self::assertSame(0, $this->codeCount());
    }

    public function testAPersonalAccessClientOfAnotherUserIsRedirectedWithUnauthorizedClientOnLookupApprovalAndDenial(): void
    {
        $client = Client::createPersonalAccess('Someone else\'s CLI', $this->createTestUser()->getId());
        static::getContainer()->get(ClientRepositoryInterface::class)->saveClient($client);
        $clientId = $client->getPublicId()->toString();
        $requestUri = $this->push($clientId, 'http://localhost:53682');

        // A denial runs the same user checks as an approval, so it cannot use up the owner's request.
        foreach ([['GET', []], ['POST', ['decision' => 'approve']], ['POST', ['decision' => 'deny']]] as [$method, $extra]) {
            $response = $this->authorize($method, ['client_id' => $clientId, 'request_uri' => $requestUri, ...$extra]);
            $error = $this->error($response);
            self::assertSame('unauthorized_client', $error['error']);
            self::assertStringStartsWith('http://localhost:53682?', $error['redirect_uri']);
            self::assertSame('pushed-state', $this->redirectOf($error)['state']);
        }
        self::assertFalse($this->isConsumed($requestUri));
    }

    public function testTheIssuedCodeCarriesThePushedThumbprintAndASixtySecondLifetime(): void
    {
        $clientId = $this->publicClient();
        $requestUri = $this->push($clientId);

        $redirect = $this->decide($clientId, $requestUri, 'approve');

        self::assertSame(static::getContainer()->getParameter('auth.oauth.issuer'), $redirect['iss']);
        $code = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT dpop_jkt, EXTRACT(EPOCH FROM expires_at - created_at)::int AS lifetime FROM oauth_auth_codes WHERE code_id = ?',
            [hash('sha256', $redirect['code'])],
        );
        self::assertIsArray($code);
        self::assertSame($this->jkt, $code['dpop_jkt']);
        self::assertSame(60, $code['lifetime']);
        self::assertTrue($this->isConsumed($requestUri));
    }

    private function publicClient(string $name = 'Pushed request player'): string
    {
        $client = Client::create($name, [self::REDIRECT, self::OTHER_REDIRECT]);
        static::getContainer()->get(ClientRepositoryInterface::class)->saveClient($client);

        return $client->getPublicId()->toString();
    }

    /** Pushes a request bound to the test key's thumbprint and answers its request URI. */
    private function push(string $clientId, string $redirectUri = self::REDIRECT): string
    {
        $this->client->request('POST', '/api/oauth/par', [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'library admin',
            'state' => 'pushed-state',
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'dpop_jkt' => $this->jkt,
        ], [], ['REMOTE_ADDR' => '198.51.100.91']);
        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR)['request_uri'];
    }

    /** @param array<string, string> $parameters */
    private function authorize(string $method, array $parameters): Response
    {
        return $method === 'GET'
            ? $this->authenticatedRequest('GET', '/api/oauth/authorize?' . http_build_query($parameters), $this->user)
            : $this->authenticatedRequest('POST', '/api/oauth/authorize', $this->user, $parameters);
    }

    /**
     * @param array<string, string> $extra Further browser parameters sent with the decision
     * @return array<string, string> the query of the redirect URI the page navigates to
     */
    private function decide(string $clientId, string $requestUri, string $decision, array $extra = []): array
    {
        $response = $this->authorize('POST', [...$extra, 'client_id' => $clientId, 'request_uri' => $requestUri, 'decision' => $decision]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $answer = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertStringStartsWith(self::REDIRECT . '?', $answer['redirect_uri']);

        return $this->redirectOf($answer);
    }

    /**
     * @param array<string, mixed> $answer
     * @return array<string, string>
     */
    private function redirectOf(array $answer): array
    {
        self::assertIsString($answer['redirect_uri'] ?? null);
        parse_str((string) parse_url($answer['redirect_uri'], PHP_URL_QUERY), $redirect);

        /** @var array<string, string> $redirect */
        return $redirect;
    }

    /** @return array<string, mixed> */
    private function error(Response $response): array
    {
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** An unusable pushed request is shown to the user and never sent back to the client. */
    private function assertUnusableRequest(Response $response): void
    {
        $error = $this->error($response);
        self::assertSame('invalid_request_uri', $error['error']);
        self::assertArrayNotHasKey('redirect_uri', $error);
    }

    private function isConsumed(string $requestUri): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(
            'SELECT consumed_at IS NOT NULL FROM oauth_pushed_authorization_requests WHERE request_id = ?',
            [self::requestId($requestUri)],
        );
    }

    private function codeCount(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM oauth_auth_codes WHERE user_id = ?',
            [$this->user->getId()->toString()],
        );
    }

    private static function requestId(string $requestUri): string
    {
        return substr($requestUri, strlen('urn:ietf:params:oauth:request_uri:'));
    }
}
