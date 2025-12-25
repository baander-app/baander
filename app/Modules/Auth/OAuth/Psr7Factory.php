<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth;

use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Service for converting between Laravel and PSR-7 HTTP messages
 *
 * Provides centralized conversion logic for OAuth server integration,
 * eliminating duplication across guards, controllers, and middleware.
 */
class Psr7Factory
{
    private readonly Psr17Factory $psrFactory;
    private readonly PsrHttpFactory $psrHttpFactory;

    public function __construct()
    {
        $this->psrFactory = new Psr17Factory();
        $this->psrHttpFactory = new PsrHttpFactory(
            $this->psrFactory,
            $this->psrFactory,
            $this->psrFactory,
            $this->psrFactory,
        );
    }

    /**
     * Convert Laravel Request to PSR-7 ServerRequest
     *
     * @param Request $request The Laravel request
     * @return ServerRequestInterface The PSR-7 request
     */
    public function createRequest(Request $request): ServerRequestInterface
    {
        return $this->psrHttpFactory->createRequest($request);
    }

    /**
     * Convert Laravel Request to PSR-7 ServerRequest with custom body
     *
     * Useful for OAuth token requests where we need to override
     * the request body with grant parameters.
     *
     * @param Request $request The Laravel request
     * @param array $bodyData Custom body data to set
     * @return ServerRequestInterface The PSR-7 request with modified body
     */
    public function createRequestWithBody(Request $request, array $bodyData): ServerRequestInterface
    {
        return $this->psrHttpFactory->createRequest($request)
            ->withParsedBody($bodyData);
    }

    /**
     * Create a new PSR-7 Response
     *
     * @return ResponseInterface Empty PSR-7 response
     */
    public function createResponse(): ResponseInterface
    {
        return $this->psrFactory->createResponse();
    }

    /**
     * Create PSR-7 Response from Laravel Response
     *
     * @param Response $response The Laravel response
     * @return ResponseInterface The PSR-7 response
     */
    public function createResponseFromLaravel(Response $response): ResponseInterface
    {
        return $this->psrHttpFactory->createResponse($response);
    }

    /**
     * Convert PSR-7 Response to Laravel Response
     *
     * @param ResponseInterface $psrResponse The PSR-7 response
     * @return Response The Laravel response
     */
    public function toLaravelResponse(ResponseInterface $psrResponse): Response
    {
        return new Response(
            $psrResponse->getBody()->getContents(),
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders(),
        );
    }
}
