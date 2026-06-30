<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Nelmio\ApiDocBundle\Render\RenderOpenApi;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OpenApiSpecTest extends KernelTestCase
{
    private function getSpec(): array
    {
        self::bootKernel();

        /** @var RenderOpenApi $renderOpenApi */
        $renderOpenApi = self::getContainer()->get(RenderOpenApi::class);
        $content = $renderOpenApi->render('json', 'default');

        /** @var array<string, mixed> $spec */
        $spec = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $spec;
    }

    public function test_spec_is_valid_json(): void
    {
        $spec = $this->getSpec();

        $this->assertIsArray($spec);
    }

    public function test_spec_is_openapi_3(): void
    {
        $spec = $this->getSpec();

        $this->assertArrayHasKey('openapi', $spec);
        $this->assertStringStartsWith('3.', $spec['openapi']);
    }

    public function test_spec_has_info_block(): void
    {
        $spec = $this->getSpec();

        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('title', $spec['info']);
        $this->assertArrayHasKey('version', $spec['info']);
        $this->assertNotEmpty($spec['info']['title']);
        $this->assertNotEmpty($spec['info']['version']);
    }

    public function test_spec_has_minimum_60_paths(): void
    {
        $spec = $this->getSpec();
        $paths = $spec['paths'] ?? [];

        $this->assertGreaterThanOrEqual(60, count($paths), sprintf(
            'Expected at least 60 paths, got %d',
            count($paths),
        ));
    }

    public function test_spec_has_all_required_schemas(): void
    {
        $spec = $this->getSpec();
        $schemas = array_keys($spec['components']['schemas'] ?? []);

        $required = [
            // Shared DTOs
            'ApiError',
            'ValidationError',
            'OAuthError',
            'PaginatedResponse',
            'CursorPaginatedResponse',
            // Auth request DTOs
            'RegisterRequest',
            'LoginRequest',
            'RefreshTokenRequest',
            'RequestPasswordResetRequest',
            'VerifyEmailRequest',
            'UpdateProfileRequest',
            'CreateClientRequest',
            'EnableTotpRequest',
            'DisableTotpRequest',
            'RegisterPasskeyRequest',
            'WebAuthnOptionsRequest',
            'VerifyPasskeyChallengeRequest',
            'RevokeTokenRequest',
            'DeviceAuthorizeRequest',
            'DeviceApproveRequest',
            // Auth resources
            'UserResource',
            'TokenResource',
            // Catalog request DTOs
            'UpdateAlbumRequest',
            'UpdateArtistRequest',
            'UpdateSongRequest',
            'UpdateMovieRequest',
            'UpdateGenreRequest',
            // Catalog resources
            'AlbumResource',
            'ArtistResource',
            'SongResource',
            'MovieResource',
            'GenreResource',
            // Other contexts
            'PlaylistResource',
            'CreatePlaylistRequest',
            'UpdatePlaylistRequest',
            'AddSongRequest',
            'ReorderSongsRequest',
            'LibraryResource',
            'CreateLibraryRequest',
            'UpdateLibraryRequest',
            'ActivityResource',
            'PlayActivityRequest',
            'ImageResource',
            'ExtractMetadataRequest',
            'MatchMetadataRequest',
        ];

        $missing = array_diff($required, $schemas);
        implode(', ', $missing)
            |> (fn($x) => sprintf('Missing schemas: %s', $x,))
            |> (fn($x) => $this->assertEmpty($missing, $x));
    }

    public function test_api_error_schema_has_correct_structure(): void
    {
        $spec = $this->getSpec();
        $apiError = $spec['components']['schemas']['ApiError'] ?? [];

        $this->assertArrayHasKey('properties', $apiError);

        $properties = $apiError['properties'];
        $this->assertArrayHasKey('message', $properties, 'ApiError must have a "message" property');
        $this->assertArrayHasKey('code', $properties, 'ApiError must have a "code" property');
    }

    public function test_every_path_has_at_least_one_response_with_schema(): void
    {
        $spec = $this->getSpec();
        $paths = $spec['paths'] ?? [];

        // Endpoints that legitimately return no JSON schema (streaming, SSE, 204)
        $skipPaths = [
            'GET /api/images/{publicId}/file',  // Binary file response
            'GET /api/stream/media',            // Binary stream response
            // Streaming/binary endpoints
            'GET /api/stream/track',            // Binary audio stream
            'GET /api/stream/{videoId}/master.m3u8',  // HLS manifest
            'GET /api/stream/{jobPublicId}/media.m3u8',  // HLS manifest
            'GET /api/stream/{videoId}/manifest.mpd',  // DASH manifest
            'GET /api/stream/{jobPublicId}/init.mp4',  // CMAF init segment
            'GET /api/stream/{jobPublicId}/seg_{index}.m4s',  // CMAF media segment
            'GET /api/stream/{jobPublicId}/audio/{language}/init.mp4',  // Audio init segment
            'GET /api/stream/{jobPublicId}/audio/{language}/seg_{index}.m4s',  // Audio media segment
            'GET /api/stream/{jobPublicId}/audio/{language}/media.m3u8',  // HLS audio manifest
            'GET /api/stream/{jobPublicId}/subtitles/{language}/media.m3u8',  // HLS subtitle manifest
            'GET /api/stream/{jobPublicId}/subtitles/{language}/{segment}.vtt',  // WebVTT subtitle
            // DELETE endpoints returning 204 — Nelmio generates route-based entries
            // that don't inherit the OA annotation's 204 response status check.
            'DELETE /api/push/subscribe',
            'DELETE /api/push/subscriptions',
            'DELETE /api/webhooks/{id}',
        ];

        $failures = [];

        foreach ($paths as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $key = sprintf('%s %s', strtoupper($method), $path);
                if (in_array($key, $skipPaths, true)) {
                    continue;
                }

                $responses = $operation['responses'] ?? [];
                $hasSchema = false;

                foreach ($responses as $status => $response) {
                    $content = $response['content'] ?? [];
                    if (isset($content['application/json']['schema'])) {
                        $hasSchema = true;
                        break;
                    }
                    // 204 No Content has no body
                    if ($status == '204') {
                        $hasSchema = true;
                        break;
                    }
                    // "default" response key is accepted (Nelmio fallback)
                    if ($status === 'default' && isset($content['application/json']['schema'])) {
                        $hasSchema = true;
                        break;
                    }
                }

                if (!$hasSchema) {
                    $failures[] = $key;
                }
            }
        }

        implode(', ', $failures)
            |> (fn($x) => sprintf('Paths without schema in any response: %s', $x,))
            |> (fn($x) => $this->assertEmpty($failures, $x));
    }

    public function test_public_endpoints_have_no_security(): void
    {
        $spec = $this->getSpec();
        $publicPaths = [
            '/api/auth/register' => 'POST',
            '/api/auth/login' => 'POST',
            '/api/auth/login/passkey' => 'POST',
            '/api/auth/password/reset-request' => 'POST',
            '/api/auth/email/verify' => 'POST',
            '/api/oauth/authorize' => 'GET',
            '/api/oauth/device/authorize' => 'POST',
            '/api/oauth/device/verify' => 'GET',
        ];

        foreach ($publicPaths as $path => $method) {
            $operation = $spec['paths'][$path][strtolower($method)] ?? null;
            $this->assertNotNull($operation, sprintf('Missing public endpoint: %s %s', $method, $path));

            $security = $operation['security'] ?? null;
            $this->assertSame([], $security, sprintf(
                'Public endpoint %s %s should have empty security override',
                $method,
                $path,
            ));
        }
    }

    public function test_spec_has_security_schemes(): void
    {
        $spec = $this->getSpec();
        $schemes = $spec['components']['securitySchemes'] ?? [];

        $this->assertArrayHasKey('bearerAuth', $schemes);
        $this->assertSame('http', $schemes['bearerAuth']['type']);
        $this->assertSame('bearer', $schemes['bearerAuth']['scheme']);
    }

    public function test_spec_has_tags(): void
    {
        $spec = $this->getSpec();
        $paths = $spec['paths'] ?? [];
        $tags = [];

        foreach ($paths as $methods) {
            foreach ($methods as $operation) {
                foreach ($operation['tags'] ?? [] as $tag) {
                    $tags[$tag] = true;
                }
            }
        }

        $this->assertArrayHasKey('Auth', $tags);
        $this->assertArrayHasKey('Catalog', $tags);
        $this->assertArrayHasKey('Playlist', $tags);
        $this->assertArrayHasKey('Activity', $tags);
        $this->assertArrayHasKey('Library', $tags);
        $this->assertArrayHasKey('Media', $tags);
        $this->assertArrayHasKey('Metadata', $tags);
    }
}
