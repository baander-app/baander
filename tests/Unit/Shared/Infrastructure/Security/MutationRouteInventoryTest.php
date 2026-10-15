<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Security;

use App\Tests\Integration\CatalogMutationFirewallTest;
use App\Tests\Integration\PlaylistMutationFirewallTest;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\Loader\Psr4DirectoryLoader;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Every API route that accepts a method other than GET or HEAD is either owned by another
 * suite, named here with that suite or the reason, or has its rows in the catalog or the
 * playlist mutation matrix. A new mutation route fails this test until it is placed, so the
 * release evidence those matrices give stays complete after the next route lands.
 *
 * The routes come from `config/routes.yaml` through the routing component's own loaders;
 * compiling the container would exceed the unit suite's memory budget.
 */
final class MutationRouteInventoryTest extends TestCase
{
    private const string API_PREFIX = '/api/';
    private const string PLAYLIST_SUITE = 'App\Tests\Integration\PlaylistMutationFirewallTest';

    /**
     * Mutation routes with rows in another suite or outside the catalog, library and playlist
     * surface, by the suite or the reason. Names are exact: a route added to any of these
     * areas is placed here by hand or gets matrix rows.
     */
    private const array EXCLUDED = [
        'artist mutations, ArtistMutationFirewallTest' => [
            'artist_store', 'artist_update', 'artist_destroy',
            'artist_add_song', 'artist_remove_song', 'artist_update_song_role',
            'artist_add_album', 'artist_remove_album', 'artist_update_album_role',
        ],
        'lyrics, LyricsFirewallTest' => ['lyrics_apply', 'lyrics_song_lyrics_fetch', 'admin_lyrics_bulk_fetch'],
        'user and library-access administration' => [
            'admin_user_libraries_grant', 'admin_user_libraries_revoke',
            'admin_users_create', 'admin_users_update', 'admin_users_delete', 'admin_users_disable', 'admin_users_enable',
            'admin_users_assign_roles', 'admin_users_reset_password',
            'admin_user_settings_set', 'admin_user_settings_reset',
            'admin_login_blocks_delete', 'admin_login_blocks_delete_all',
            'admin_oauth_clients_create', 'admin_oauth_clients_revoke', 'admin_oauth_clients_rotate_secret',
        ],
        'WebSocket ticket issuance, OAuthFirewallAcceptanceTest' => ['auth_websocket_ticket'],
        'authentication and OAuth' => [
            'auth_login_login', 'auth_logout', 'auth_refresh', 'auth_register', 'auth_email_verify',
            'auth_me_update', 'auth_me_email', 'auth_me_email_verification', 'auth_me_password',
            'auth_password_reset', 'auth_password_reset_request', 'auth_passkey_login_passkey',
            'passkey_options', 'passkey_register', 'passkey_authenticate', 'passkey_authenticate_options', 'passkey_delete',
            'totp_setup', 'totp_enable', 'totp_disable',
            'oauth_token', 'oauth_revoke', 'oauth_authorize_decision', 'oauth_clients_create', 'oauth_clients_revoke',
            'oauth_device_authorize', 'oauth_device_approve',
        ],
        'sessions and devices' => ['session_new', 'session_claim', 'session_sync', 'device_register', 'device_rename', 'device_forget'],
        'radio' => [
            'radio_session_start', 'radio_session_stop', 'radio_source_create',
            'radio_starred_star', 'radio_starred_unstar',
            'radio_subscription_subscribe', 'radio_subscription_unsubscribe', 'radio_subscription_refresh',
        ],
        'discovery' => ['discovery_register', 'discovery_pairing_code', 'discovery_complete_pairing'],
        'party' => [
            'party_session_create', 'party_session_join', 'party_session_leave', 'party_session_sync', 'party_session_end',
            'party_member_update_me', 'party_member_transfer_host',
        ],
        'favorites' => ['favorites_add', 'favorites_remove'],
        'activity' => ['activity_play', 'activity_love'],
        'notifications, push subscriptions and webhooks' => [
            'notification_mark_read', 'notification_mark_all_read', 'notification_delete', 'notification_preference_update',
            'push_subscribe', 'push_unsubscribe', 'push_remove_all',
            'webhook_create', 'webhook_update', 'webhook_delete', 'webhook_rotate_secret',
        ],
        'recommendations' => [
            'recommendation_store', 'recommendation_destroy', 'recommendation_destroy_by_source',
            'admin_recommendations_generate', 'admin_recommendations_job_cancel', 'admin_recommendations_job_requeue',
        ],
        'QoL administration' => ['admin_qol_profile', 'admin_qol_reset'],
        'media and metadata administration' => ['admin_media_prune_missing', 'admin_metadata_trigger_sync', 'metadata_extract', 'metadata_match'],
        'the signing endpoint, SignedDeliveryFirewallTest' => ['stream_signing_sign'],
        'transcode sessions and jobs' => [
            'transcode_session_create', 'transcode_session_update', 'transcode_session_cancel',
            'transcode_session_pause', 'transcode_session_resume', 'transcode_session_position',
            'transcode_job_cleanup',
        ],
        'user preferences' => [
            'accent_color_update', 'audio_preferences_update', 'audio_preferences_rollback',
            'eq_profiles_create', 'eq_profiles_update', 'eq_profiles_delete', 'eq_profiles_activate',
            'layout_preferences_update', 'layout_preferences_rollback',
            'player_preferences_update', 'player_preferences_rollback',
            'sidebar_config_update', 'sidebar_config_delete', 'theme_mood_update',
            'user_settings_set', 'user_settings_reset',
        ],
        'operator monitoring, settings and scheduling' => [
            'monitor_jobs_cancel', 'monitor_jobs_retry', 'monitor_prune',
            'monitor_ratelimiters_clear', 'monitor_ratelimiters_clear_all',
            'monitor_transport_failed_retry', 'monitor_transport_failed_remove', 'monitor_transport_failed_flush',
            'admin_settings_update', 'admin_settings_reset',
            'admin_scheduler_create', 'admin_scheduler_update', 'admin_scheduler_delete',
            'admin_scheduler_enable', 'admin_scheduler_disable', 'admin_scheduler_pause', 'admin_scheduler_resume', 'admin_scheduler_trigger',
            'debug_spans_clear',
        ],
    ];

    private static RouteCollection $routes;

    public static function setUpBeforeClass(): void
    {
        $locator = new FileLocator([self::projectDir() . '/config']);
        $classLoader = new AttributeRouteControllerLoader('prod');
        $loader = new DelegatingLoader(new LoaderResolver([
            new YamlFileLoader($locator, 'prod'),
            $classLoader,
            new AttributeDirectoryLoader($locator, $classLoader),
            new AttributeFileLoader($locator, $classLoader),
            new Psr4DirectoryLoader($locator),
        ]));
        $routes = $loader->load('routes.yaml');
        self::assertInstanceOf(RouteCollection::class, $routes);
        self::$routes = $routes;
    }

    public function testEveryMutationRouteIsExcludedByNameOrHasMatrixRows(): void
    {
        $mutations = self::mutationRoutes(self::$routes);
        $excluded = array_merge(...array_values(self::EXCLUDED));
        $covered = self::coveredRoutes();

        self::assertSame([], array_values(array_diff($excluded, array_keys($mutations))), 'An exclusion names a route the router no longer has.');
        self::assertSame([], array_values(array_intersect($excluded, $covered)), 'A route is both excluded and covered by a matrix.');
        self::assertSame($excluded, array_values(array_unique($excluded)), 'A route is excluded twice.');
        foreach ($covered as $name) {
            self::assertNotNull(self::$routes->get($name), 'The matrix key "' . $name . '" is not a route.');
        }

        $remainder = array_values(array_diff(array_keys($mutations), $excluded));
        self::assertEqualsCanonicalizing(
            array_values(array_intersect($covered, array_keys($mutations))),
            $remainder,
            'A mutation route is neither excluded here nor covered by the catalog or playlist matrix.',
        );
    }

    public function testANewMutationRouteFailsThePlacementUntilItIsExcludedOrCovered(): void
    {
        $routes = clone self::$routes;
        $routes->add('album_relabel', new Route('/api/albums/{publicId}/relabel', methods: ['POST']));
        $excluded = array_merge(...array_values(self::EXCLUDED));

        $unplaced = array_values(array_diff(array_keys(self::mutationRoutes($routes)), $excluded, self::coveredRoutes()));

        self::assertSame(['album_relabel'], $unplaced);
    }

    /**
     * The catalog matrix keys and the playlist matrix keys. The playlist suite is required by
     * name: a missing class or constant is a failure, not an empty set.
     *
     * @return list<string>
     */
    private static function coveredRoutes(): array
    {
        $suites = array_filter([self::PLAYLIST_SUITE], class_exists(...));
        self::assertNotSame([], $suites, 'The playlist mutation suite ' . self::PLAYLIST_SUITE . ' is missing; its route keys are part of this inventory.');
        $constants = array_filter([self::PLAYLIST_SUITE . '::ROUTE_KEYS'], defined(...));
        self::assertNotSame([], $constants, 'The playlist mutation suite must expose its route keys as the public constant ROUTE_KEYS.');
        $covered = [...CatalogMutationFirewallTest::ROUTE_KEYS, ...PlaylistMutationFirewallTest::ROUTE_KEYS];
        self::assertSame($covered, array_values(array_unique($covered)), 'A route key is listed twice across the matrices.');

        return $covered;
    }

    /**
     * Routes under the API prefix that accept a method other than GET or HEAD, by name.
     *
     * @return array<string, Route>
     */
    private static function mutationRoutes(RouteCollection $routes): array
    {
        $mutations = [];
        foreach ($routes as $name => $route) {
            if (!str_starts_with($route->getPath(), self::API_PREFIX)) {
                continue;
            }
            if ($route->getMethods() !== [] && array_diff($route->getMethods(), ['GET', 'HEAD']) === []) {
                continue;
            }
            $mutations[$name] = $route;
        }

        return $mutations;
    }

    private static function projectDir(): string
    {
        return dirname(__DIR__, 5);
    }
}
