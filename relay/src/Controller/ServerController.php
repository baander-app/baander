<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\ServerRegistry;
use App\Repository\ServerRegistryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cloud Relay REST controller.
 *
 * Three endpoints only — intentionally simple:
 *   POST /api/servers/register  — heartbeat (upsert server)
 *   GET  /api/servers/{publicId} — lookup server by public ID
 *   GET  /health                — liveness probe
 */
#[Route('/api/servers', name: 'server_')]
final class ServerController
{
    public function __construct(
        private readonly ServerRegistryRepository $repository,
    ) {
    }

    /**
     * Register or heartbeat a server.
     *
     * Servers call this periodically (e.g. every 60s) to announce they're alive.
     * If the api_key_hash already exists, the record is updated (upsert).
     * URL must use https:// scheme.
     */
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $publicId = $body['publicId'] ?? null;
        $url = $body['url'] ?? null;
        $name = $body['name'] ?? null;
        $version = $body['version'] ?? '0.0.0';
        $apiKey = $body['apiKey'] ?? null;

        if ($publicId === null || $url === null || $name === null || $apiKey === null) {
            return new JsonResponse(
                ['error' => 'Missing required fields: publicId, url, name, apiKey'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        // Validate URL scheme
        $parsedUrl = parse_url($url);
        if (!isset($parsedUrl['scheme']) || $parsedUrl['scheme'] !== 'https') {
            return new JsonResponse(
                ['error' => 'Invalid URL scheme. Only https:// is allowed.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $server = new ServerRegistry(
            publicId: $publicId,
            url: $url,
            name: $name,
            version: $version,
            apiKey: $apiKey,
        );

        $this->repository->register($server);

        // Opportunistic cleanup: remove stale servers on each registration
        $this->repository->cleanup(600);

        return new JsonResponse([
            'data' => [
                'registered' => true,
                'publicId' => $publicId,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Look up a server by its public ID.
     *
     * Used by the Android app to resolve a server code to a URL.
     */
    #[Route('/{publicId}', name: 'lookup', methods: ['GET'])]
    public function lookup(string $publicId): JsonResponse
    {
        $server = $this->repository->findByPublicId($publicId);

        if ($server === null) {
            return new JsonResponse(
                ['error' => 'Server not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse([
            'data' => $server->toArray(),
        ], Response::HTTP_OK);
    }

    /**
     * Health check endpoint for Docker/load balancer probes.
     */
    #[Route('/health', name: 'health', methods: ['GET'], priority: 10)]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ], Response::HTTP_OK);
    }
}
