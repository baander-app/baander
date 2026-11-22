<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Catalog\Application\Port\SongSortField;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Interface\DTO\ApiError;
use App\Shared\Interface\DTO\CursorPaginatedResponse;
use App\Shared\Interface\DTO\PaginatedResponse;
use App\Shared\Application\Port\OpenApiExporterInterface;
use App\Shared\Interface\Controller\OpenApiController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

final class OpenApiSpecTest extends KernelTestCase
{
    /** @return array<string, mixed> */
    private function getSpec(): array
    {
        self::bootKernel();

        /** @var OpenApiExporterInterface $renderOpenApi */
        $renderOpenApi = self::getContainer()->get(OpenApiExporterInterface::class);
        $content = $renderOpenApi->render('json');

        /** @var array<string, mixed> $spec */
        $spec = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $spec;
    }

    public function test_json_route_uses_the_same_native_exporter_as_the_command(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $router = $container->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);
        $route = $router->match('/api/doc.json');
        self::assertSame('app.swagger', $route['_route']);
        self::assertSame(OpenApiController::class, $route['_controller']);
        $controller = $container->get(OpenApiController::class);
        self::assertInstanceOf(OpenApiController::class, $controller);
        $exporter = $container->get(OpenApiExporterInterface::class);
        self::assertInstanceOf(OpenApiExporterInterface::class, $exporter);
        $response = $controller();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame($exporter->render(), $response->getContent());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_actual_application_export_loads_no_deprecated_openapi_classes(): void
    {
        $spec = $this->getSpec();
        self::assertNotEmpty($spec['paths']);
        $deprecated = array_filter(
            get_declared_classes(),
            static function (string $class): bool {
                return (str_starts_with($class, 'OpenApi\\')
                        && str_contains(new ReflectionClass($class)->getDocComment() ?: '', '@deprecated'))
                    || str_starts_with($class, 'OpenApi\\Annotations\\')
                    || str_starts_with($class, 'OpenApi\\Attributes\\')
                    || in_array($class, [
                        'OpenApi\\Context',
                        'OpenApi\\Serializer',
                        'OpenApi\\Generator',
                        'OpenApi\\Analysis',
                        'OpenApi\\Builder\\Mode',
                    ], true);
            },
        );
        self::assertSame([], array_values($deprecated));
    }

    public function test_every_api_route_operation_is_documented(): void
    {
        $spec = $this->getSpec();
        $router = self::getContainer()->get('router.default');
        self::assertInstanceOf(RouterInterface::class, $router);
        $operations = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $path = $route->getPath();
            // The document endpoint is excluded from the API it describes.
            if (!str_starts_with($path, '/api/') || $name === 'app.swagger') {
                continue;
            }
            self::assertNotEmpty($route->getMethods(), $name . ' must declare HTTP methods.');
            foreach ($route->getMethods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $operation = strtolower($method);
                self::assertArrayHasKey($path, $spec['paths'], $name . ' is missing from OpenAPI.');
                self::assertArrayHasKey($operation, $spec['paths'][$path], $name . ' is missing its method.');
                $operations[$path][$operation] = true;
            }
        }

        foreach ($spec['paths'] as $path => $item) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (isset($item[$method])) {
                    self::assertTrue(isset($operations[$path][$method]), $method . ' ' . $path . ' has no route.');
                }
            }
        }
    }

    public function test_movie_spec_parameters_preserve_the_complete_published_contract(): void
    {
        $spec = $this->getSpec();
        $operations = [
            ['/api/movies/', 'get', ['page', 'limit', 'q']],
            ['/api/movies/{publicId}', 'get', ['publicId']],
            ['/api/movies/{publicId}', 'patch', ['publicId']],
            ['/api/movies/{publicId}', 'delete', ['publicId']],
            ['/api/movies/directors', 'get', ['page', 'limit', 'q']],
            ['/api/movies/directors/{publicId}', 'get', ['publicId']],
            ['/api/movies/directors/{publicId}/movies', 'get', ['publicId', 'page', 'limit', 'q']],
        ];
        foreach ($operations as [$path, $method, $names]) {
            $parameters = $spec['paths'][$path][$method]['parameters'];
            self::assertSame($names, array_column($parameters, 'name'), $method . ' ' . $path);
        }
        $published = json_decode(file_get_contents(dirname(__DIR__, 2) . '/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(self::normalizedContract($published), self::normalizedContract($spec));
    }

    private static function normalizedContract(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::normalizedContract(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    public function test_request_schemas_expose_supported_choices(): void
    {
        $spec = $this->getSpec();
        $schemas = $spec['components']['schemas'];
        $hint = $schemas['RevokeTokenRequest']['properties']['tokenTypeHint'];
        $revoke = $spec['paths']['/api/oauth/revoke']['post']['requestBody']['content']['application/json']['schema'];
        self::assertSame(['access_token', 'refresh_token'], $hint['enum']);
        self::assertArrayNotHasKey('default', $hint);
        self::assertSame($hint, $revoke['properties']['tokenTypeHint']);
        self::assertNotContains('tokenTypeHint', $revoke['required']);

        $library = $schemas['CreateLibraryRequest']['properties'];
        self::assertSame(['music', 'podcast', 'audiobook', 'movie', 'tv_show'], $library['type']['enum']);
        self::assertArrayNotHasKey('default', $library['type']);
        self::assertSame(['local'], $library['filesystemType']['enum']);
        self::assertSame('local', $library['filesystemType']['default']);

        $roles = ['primary', 'featured', 'producer', 'composer', 'conductor', 'remixer', 'djmix', 'other'];
        foreach (['ArtistAlbumRequest', 'ArtistSongRequest', 'UpdateRoleRequest'] as $request) {
            self::assertSame($roles, $schemas[$request]['properties']['role']['enum']);
        }
        $currentRole = $schemas['UpdateRoleRequest']['properties']['currentRole'];
        self::assertSame([...$roles, null], $currentRole['enum']);
        self::assertTrue($currentRole['nullable']);
        self::assertArrayHasKey('default', $currentRole);
        self::assertNull($currentRole['default']);

        $capabilities = $schemas['RegisterPlaybackEndpointRequest']['properties']['capabilities'];
        self::assertSame(['song', 'video', 'volume'], $capabilities['items']['enum']);
        self::assertSame(['song', 'video'], $capabilities['default']);

        foreach ([
            'AdminCreateOAuthClientRequest' => 'type',
            'DeviceApproveRequest' => 'decision',
            'CreatePairingCodeRequest' => 'method',
        ] as $request => $field) {
            $property = $schemas[$request]['properties'][$field];
            self::assertNotEmpty($property['enum'], $request . '.' . $field);
            self::assertArrayNotHasKey('default', $property, $request . '.' . $field);
        }

        $themeMood = $spec['paths']['/api/user/theme-mood/']['put']['requestBody']['content']['application/json']['schema'];
        self::assertSame(['dark', 'warm', 'cool', 'balanced'], $themeMood['properties']['mood']['enum']);
        self::assertArrayNotHasKey('default', $themeMood['properties']['mood']);

        foreach ([
            'AdminCreateOAuthClientRequest' => 'authMethod',
            'DeliveryRequest' => 'qualityTier',
            'AcknowledgePlaybackRequest' => 'rejectionReason',
            'ControlPlaybackRequest' => 'desiredState',
            'UpdateTranscodeSessionRequest' => 'audioProfile',
        ] as $request => $field) {
            $property = $schemas[$request]['properties'][$field];
            self::assertTrue($property['nullable']);
            self::assertArrayHasKey('default', $property);
            self::assertNull($property['default']);
            self::assertContains(null, $property['enum']);
        }

        $media = $schemas['ControlPlaybackRequest']['properties']['media'];
        $kind = ['type' => 'string', 'enum' => ['song', 'video']];
        self::assertTrue($media['nullable']);
        [$mediaMap, $mediaChoices] = $media['allOf'];
        self::assertSame(['type' => 'string'], $mediaMap['additionalProperties']);
        self::assertTrue($mediaMap['nullable']);
        self::assertTrue($mediaChoices['nullable']);
        self::assertSame($kind, $mediaChoices['properties']['kind']);

        $checkpointValues = [
            ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            ['type' => 'number', 'format' => 'float'],
            ['type' => 'integer'],
            ['type' => 'object', 'nullable' => true, 'enum' => [null]],
        ];
        foreach (['ClosePlaybackEndpointRequest', 'RecoverLocalPlaybackRequest'] as $request) {
            $checkpoint = $schemas[$request]['properties']['checkpoint'];
            [$checkpointMap, $checkpointChoices] = $checkpoint['allOf'];
            $branches = $checkpointChoices['properties']['media']['oneOf'];
            [$mediaMap, $mediaChoices] = $branches[0]['allOf'];
            self::assertSame(['kind' => $kind], $mediaChoices['properties']);
            $branches[0] = $mediaMap;
            self::assertSame($checkpointValues, $checkpointMap['additionalProperties']['oneOf']);
            self::assertSame($checkpointValues, $branches);
        }
    }

    public function test_song_list_sort_enum_lists_the_supported_fields(): void
    {
        $spec = $this->getSpec();
        $parameters = array_column($spec['paths']['/api/songs/']['get']['parameters'], null, 'name');

        self::assertSame(SongSortField::values(), $parameters['sort']['schema']['enum']);
        self::assertSame(['asc', 'desc'], $parameters['order']['schema']['enum']);
    }

    public function test_public_identifier_schemas_match_runtime_identifiers(): void
    {
        $spec = $this->getSpec();
        $schemas = $spec['components']['schemas'];
        foreach ([
            'ActivityResource' => ['publicId'],
            'RecentItemResource' => ['publicId', 'songPublicId', 'albumPublicId'],
            'AdminUserResource' => ['publicId'],
            'ClientResource' => ['publicId'],
            'UserResource' => ['publicId'],
            'ServerInstanceResource' => ['publicId'],
            'PairingSessionResource' => ['publicId', 'serverPublicId'],
            'CreatePairingCodeRequest' => ['serverPublicId'],
            'CompletePairingRequest' => ['serverPublicId'],
            'PartySessionResource' => ['publicId'],
            'TranscodeMetricsResource' => ['publicId'],
            'TranscodeSessionResource' => ['publicId'],
            'ApplyLyricsRequest' => ['songPublicId'],
        ] as $name => $fields) {
            foreach ($fields as $field) {
                $property = $schemas[$name]['properties'][$field];
                $this->assertPublicIdentifierSchema($property);
                self::assertSame($name === 'RecentItemResource' && $field !== 'publicId', $property['nullable'] ?? false);
            }
        }
    }

    public function test_server_paths_are_optional_because_only_administrators_read_them(): void
    {
        $schemas = $this->getSpec()['components']['schemas'];

        foreach (['LibraryResource', 'SongResource'] as $name) {
            self::assertArrayHasKey('path', $schemas[$name]['properties'], $name);
            self::assertNotContains('path', $schemas[$name]['required'], $name);
            self::assertStringContainsString('administrators', $schemas[$name]['properties']['path']['description'], $name);
        }
    }

    public function test_discovery_inline_public_identifiers_match_runtime_identifiers(): void
    {
        $paths = $this->getSpec()['paths'];
        foreach (['/api/discovery/pairing-code', '/api/discovery/complete-pairing'] as $path) {
            $schema = $paths[$path]['post']['requestBody']['content']['application/json']['schema'];
            $this->assertPublicIdentifierSchema($schema['properties']['serverPublicId']);
        }
        $parameters = array_column($paths['/api/discovery/qr-payload/{serverPublicId}']['post']['parameters'], null, 'name');
        $this->assertPublicIdentifierSchema($parameters['serverPublicId']['schema']);
    }

    public function test_entity_references_use_public_ids_and_device_tokens_keep_their_protocol_contract(): void
    {
        $spec = $this->getSpec();
        foreach ([
            'ActivityResource' => 'songId',
            'CreateTranscodeSessionRequest' => 'videoId',
            'TranscodeSessionResource' => 'videoId',
            'PartySessionResource' => 'publicId',
            'AddSongRequest' => 'songId',
            'SongResource' => 'albumId',
        ] as $name => $field) {
            $this->assertPublicIdentifierSchema($spec['components']['schemas'][$name]['properties'][$field]);
        }
        foreach ($spec['components']['schemas'] as $name => $schema) {
            if (!str_ends_with($name, 'Resource')) {
                continue;
            }
            self::assertArrayNotHasKey('uuid', $schema['properties'] ?? [], $name);
            foreach ($schema['properties'] ?? [] as $field => $property) {
                self::assertNotSame('uuid', $property['format'] ?? null, $name . '.' . $field);
            }
        }
        self::assertSame('uuid', $spec['components']['schemas']['RegisterDeviceRequest']['properties']['deviceId']['format']);
        $passkeys = $spec['paths']['/api/auth/passkey']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertPublicIdentifierSchema($passkeys['properties']['data']['items']['properties']['publicId']);
        $this->assertPublicIdentifierSchema($spec['components']['schemas']['ReorderSongsRequest']['properties']['songIds']['items']);
        $inlineReorder = $spec['paths']['/api/playlists/{publicId}/reorder']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertPublicIdentifierSchema($inlineReorder['properties']['songIds']['items']);
    }

    public function test_playlist_reorder_requires_the_displayed_revision_and_documents_conflicts(): void
    {
        $spec = $this->getSpec();
        $operation = $spec['paths']['/api/playlists/{publicId}/reorder']['post'];
        $inline = $operation['requestBody']['content']['application/json']['schema'];
        foreach ([$spec['components']['schemas']['ReorderSongsRequest'], $inline] as $schema) {
            self::assertContains('expectedRevision', $schema['required']);
            $revision = $schema['properties']['expectedRevision'];
            self::assertSame('string', $revision['type']);
            self::assertSame('^[a-f0-9]{64}$', $revision['pattern']);
            self::assertSame(64, $revision['minLength']);
            self::assertSame(64, $revision['maxLength']);
        }
        self::assertArrayHasKey('409', $operation['responses']);
        $result = $operation['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertArrayHasKey('revision', $result['properties']);
        $detail = $spec['paths']['/api/playlists/{publicId}']['get']['responses']['200']['content']['application/json']['schema']['properties']['data'];
        self::assertArrayHasKey('revision', $detail['properties']);
        self::assertArrayNotHasKey('revision', $spec['components']['schemas']['PlaylistResource']['properties']);
        self::assertArrayHasKey('409', $spec['paths']['/api/playlists/{publicId}/collaborators/{userPublicId}']['put']['responses']);
    }

    /** @param array<string, mixed> $schema */
    private function assertPublicIdentifierSchema(array $schema): void
    {
        self::assertSame('string', $schema['type']);
        self::assertArrayNotHasKey('format', $schema);
        self::assertSame(PublicId::LENGTH, $schema['minLength']);
        self::assertSame(PublicId::LENGTH, $schema['maxLength']);
        self::assertSame(PublicId::SCHEMA_PATTERN, $schema['pattern']);
        self::assertMatchesRegularExpression('~' . $schema['pattern'] . '~', (new PublicId())->toString());
    }

    public function test_configuration_object_defaults_match_their_schema_type(): void
    {
        self::bootKernel();
        $renderer = self::getContainer()->get(OpenApiExporterInterface::class);
        self::assertInstanceOf(OpenApiExporterInterface::class, $renderer);
        $spec = json_decode($renderer->render('json'), false, 512, JSON_THROW_ON_ERROR);

        foreach ([
            'CreateRadioSourceRequest' => 'syncConfig',
            'CreateScheduledJobRequest' => 'parameters',
            'UpdateScheduledJobRequest' => 'parameters',
        ] as $name => $field) {
            $property = $spec->components->schemas->{$name}->properties->{$field};
            self::assertSame('object', $property->type);
            self::assertEquals(new \stdClass(), $property->default, $name . ' must default to a JSON object.');
        }
    }

    public function test_layout_save_schema_documents_supported_panel_values(): void
    {
        $spec = $this->getSpec();
        $body = $spec['paths']['/api/user/layout-preferences/']['put']['requestBody']['content']['application/json']['schema'];
        self::assertSame('#/components/schemas/SaveLayoutPreferencesRequest', $body['$ref']);
        $schema = $spec['components']['schemas']['SaveLayoutPreferencesRequest'];
        self::assertEqualsCanonicalizing(['payload', 'version'], $schema['required']);
        $payload = $schema['properties']['payload'];
        self::assertSame('object', $payload['type']);
        self::assertArrayNotHasKey('default', $payload);
        self::assertArrayNotHasKey('default', $schema['properties']['version']);
        self::assertEqualsCanonicalizing(['mode', 'activeTab'], $payload['required']);
        self::assertSame(['compact', 'expanded'], $payload['properties']['mode']['enum']);
        self::assertSame(['queue', 'lyrics', 'details', 'info'], $payload['properties']['activeTab']['enum']);
        self::assertSame('integer', $schema['properties']['version']['type']);
        self::assertSame(0, $schema['properties']['version']['minimum']);
    }

    public function test_preference_write_errors_use_the_shared_api_envelope(): void
    {
        $spec = $this->getSpec();
        foreach (['audio', 'player', 'layout'] as $preference) {
            foreach (['put' => '', 'post' => 'rollback'] as $method => $suffix) {
                $operation = $spec['paths']['/api/user/' . $preference . '-preferences/' . $suffix][$method];
                foreach (['400', '422'] as $status) {
                    self::assertSame(
                        '#/components/schemas/ApiError',
                        $operation['responses'][$status]['content']['application/json']['schema']['$ref'],
                    );
                }
            }
        }
    }

    public function test_player_save_schema_requires_typed_bounded_fields(): void
    {
        $spec = $this->getSpec();
        $body = $spec['paths']['/api/user/player-preferences/']['put']['requestBody']['content']['application/json']['schema'];
        self::assertSame('#/components/schemas/SavePlayerPreferencesRequest', $body['$ref']);
        $schema = $spec['components']['schemas']['SavePlayerPreferencesRequest'];
        self::assertEqualsCanonicalizing(['payload', 'version'], $schema['required']);
        $payload = $schema['properties']['payload'];
        self::assertSame('object', $payload['type']);
        self::assertFalse($payload['additionalProperties']);
        self::assertArrayNotHasKey('default', $payload);
        self::assertEqualsCanonicalizing([
            'shuffle', 'repeat', 'volume', 'muted', 'crossfadeEnabled', 'crossfadeDuration',
            'replayGainEnabled', 'replayGainMode', 'replayGainPreAmp',
        ], $payload['required']);
        foreach (['volume' => [0, 1], 'crossfadeDuration' => [0, 12], 'replayGainPreAmp' => [-15, 15]] as $field => [$min, $max]) {
            self::assertSame('number', $payload['properties'][$field]['type']);
            self::assertSame($min, $payload['properties'][$field]['minimum']);
            self::assertSame($max, $payload['properties'][$field]['maximum']);
        }
    }

    public function test_audio_save_schema_matches_the_canonical_flat_contract(): void
    {
        $spec = $this->getSpec();
        $body = $spec['paths']['/api/user/audio-preferences/']['put']['requestBody']['content']['application/json']['schema'];
        self::assertSame('#/components/schemas/SaveAudioPreferencesRequest', $body['$ref']);
        $schema = $spec['components']['schemas']['SaveAudioPreferencesRequest'];
        self::assertEqualsCanonicalizing(['payload', 'version'], $schema['required']);
        $payload = $schema['properties']['payload'];
        $fields = [
            'enabled', 'bands', 'preset', 'visualizerMode', 'compressionEnabled',
            'compressorThreshold', 'compressorRatio', 'compressorKnee', 'compressorAttack', 'compressorRelease',
            'masterGain', 'normalizationEnabled', 'targetLufs', 'stereoEnabled', 'stereoWidth',
            'stereoMode', 'crossfeedEnabled', 'crossfeedPreset', 'loudnessContourEnabled', 'chainOrder',
        ];
        self::assertEqualsCanonicalizing($fields, $payload['required']);
        self::assertEqualsCanonicalizing($fields, array_keys($payload['properties']));
        self::assertSame('object', $payload['type']);
        self::assertFalse($payload['additionalProperties']);
        self::assertArrayNotHasKey('default', $payload);
        $bands = $payload['properties']['bands'];
        self::assertSame('array', $bands['type']);
        self::assertSame(10, $bands['minItems']);
        self::assertSame(10, $bands['maxItems']);
        self::assertEqualsCanonicalizing(['gain', 'q'], $bands['items']['required']);
        self::assertFalse($bands['items']['additionalProperties']);
        self::assertSame(-12, $bands['items']['properties']['gain']['minimum']);
        self::assertSame(12, $bands['items']['properties']['gain']['maximum']);
        self::assertSame(0.1, $bands['items']['properties']['q']['minimum']);
        self::assertSame(10, $bands['items']['properties']['q']['maximum']);
        $chain = $payload['properties']['chainOrder'];
        self::assertSame(6, $chain['minItems']);
        self::assertSame(6, $chain['maxItems']);
        self::assertTrue($chain['uniqueItems']);
        self::assertEqualsCanonicalizing(['eq', 'compressor', 'stereo', 'crossfeed', 'loudness', 'masterGain'], $chain['items']['enum']);
    }

    public function test_raw_path_stream_is_absent_from_contract(): void
    {
        $spec = $this->getSpec();
        self::assertArrayNotHasKey('/api/stream/media', $spec['paths']);
    }

    public function test_spec_is_valid_json(): void
    {
        self::bootKernel();
        $renderer = self::getContainer()->get(OpenApiExporterInterface::class);
        self::assertInstanceOf(OpenApiExporterInterface::class, $renderer);
        self::assertJson($renderer->render('json'));
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
            |> (fn($x) => sprintf('Missing schemas: %s', $x, ))
            |> (fn($x) => $this->assertEmpty($missing, $x));
    }

    public function test_api_error_schema_has_correct_structure(): void
    {
        $spec = $this->getSpec();
        $apiError = $spec['components']['schemas']['ApiError'] ?? [];

        self::assertSame(['error'], $apiError['required']);
        self::assertSame(['error'], array_keys($apiError['properties']));
        $error = $apiError['properties']['error'];
        self::assertSame('object', $error['type']);
        self::assertSame(['message', 'code'], $error['required']);
        self::assertSame('string', $error['properties']['message']['type']);
        self::assertSame('integer', $error['properties']['code']['type']);
        self::assertSame('object', $error['properties']['details']['type']);

        foreach ([[], ['limit' => ['Must be positive.']]] as $details) {
            $payload = (new ApiError('Invalid query parameters.', 400, $details))->toArray();
            self::assertSame(array_keys($apiError['properties']), array_keys($payload));
            self::assertSame([], array_diff(array_keys($payload['error']), array_keys($error['properties'])));
            self::assertSame([], array_diff($error['required'], array_keys($payload['error'])));
            self::assertSame($details !== [], array_key_exists('details', $payload['error']));
        }
    }

    /** @return iterable<string, array{PaginatedResponse|CursorPaginatedResponse, string}> */
    public static function paginationResponses(): iterable
    {
        yield 'page' => [new PaginatedResponse([['publicId' => 'item']], 2, 3, 20, 45), 'PaginatedResponse'];
        yield 'empty page' => [new PaginatedResponse([], 1, 1, 20, 0), 'PaginatedResponse'];
        yield 'cursor middle' => [new CursorPaginatedResponse([['publicId' => 'item']], 'next', 'previous', true, true, 45, false, 20), 'CursorPaginatedResponse'];
        yield 'cursor empty' => [new CursorPaginatedResponse([], null, null, false, false, 0, false, 20), 'CursorPaginatedResponse'];
    }

    #[DataProvider('paginationResponses')]
    public function test_pagination_schemas_match_runtime_envelopes(PaginatedResponse|CursorPaginatedResponse $response, string $schemaName): void
    {
        $spec = $this->getSpec();
        $schema = $spec['components']['schemas'][$schemaName];
        $payload = $response->toArray();
        self::assertEqualsCanonicalizing(['data', 'meta'], array_keys($schema['properties']));
        self::assertEqualsCanonicalizing(array_keys($payload), $schema['required']);
        self::assertSame('array', $schema['properties']['data']['type']);
        $meta = $schema['properties']['meta'];
        self::assertSame('object', $meta['type']);
        self::assertEqualsCanonicalizing(array_keys($payload['meta']), array_keys($meta['properties']));
        self::assertEqualsCanonicalizing(array_keys($payload['meta']), $meta['required']);
        foreach ($payload['meta'] as $field => $value) {
            $property = $meta['properties'][$field];
            if ($field === 'next_cursor' || $field === 'prev_cursor') {
                self::assertTrue($property['nullable']);
                self::assertSame('string', $property['type']);
            } else {
                self::assertSame(is_bool($value) ? 'boolean' : 'integer', $property['type']);
            }
        }
    }

    public function test_every_path_has_at_least_one_response_with_schema(): void
    {
        $spec = $this->getSpec();
        $paths = $spec['paths'] ?? [];

        // Endpoints that legitimately return no JSON schema (streaming, SSE, 204)
        $skipPaths = [
            'GET /api/images/{publicId}/file',  // Binary file response
            'GET /api/videos/{publicId}/timeline-preview',  // JPEG contact sheet
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
            'GET /api/transcode/{videoId}/master.m3u8',  // HLS manifest
            'GET /api/transcode/{jobPublicId}/tracks/{track}/media.m3u8',  // HLS manifest
            'GET /api/transcode/{videoId}/manifest.mpd',  // DASH manifest
            'GET /api/transcode/{jobPublicId}/init.mp4',  // CMAF init segment
            'GET /api/transcode/{jobPublicId}/seg_{index}.m4s',  // CMAF media segment
            'GET /api/transcode/{jobPublicId}/audio/{language}/init.mp4',  // Audio init segment
            'GET /api/transcode/{jobPublicId}/audio/{language}/seg_{index}.m4s',  // Audio media segment
            'GET /api/transcode/{jobPublicId}/audio/{language}/media.m3u8',  // HLS audio manifest
            'GET /api/transcode/{jobPublicId}/subtitles/{language}/media.m3u8',  // HLS subtitle manifest
            'GET /api/transcode/{jobPublicId}/subtitles/{language}/{segment}.vtt',  // WebVTT subtitle
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
            |> (fn($x) => sprintf('Paths without schema in any response: %s', $x, ))
            |> (fn($x) => $this->assertEmpty($failures, $x));
    }

    public function test_public_endpoints_have_no_security(): void
    {
        $spec = $this->getSpec();
        $publicPaths = [
            '/api/auth/register' => 'POST',
            '/api/auth/registration' => 'GET',
            '/api/auth/login' => 'POST',
            '/api/auth/login/passkey' => 'POST',
            '/api/auth/password/reset-request' => 'POST',
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

    /** A verification link is redeemed by a signed-in session of the account it was sent for. */
    public function test_email_verification_needs_the_accounts_session(): void
    {
        $operation = $this->getSpec()['paths']['/api/auth/email/verify']['post'];

        $this->assertArrayNotHasKey('security', $operation, 'The operation keeps the document-wide bearer requirement.');
        $this->assertArrayHasKey('401', $operation['responses']);
        $this->assertArrayHasKey('403', $operation['responses']);
        $this->assertSame(
            ['type' => 'boolean'],
            array_intersect_key(
                $operation['responses']['200']['content']['application/json']['schema']['properties']['data']['properties']['emailChanged'] ?? [],
                ['type' => true],
            ),
        );
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
