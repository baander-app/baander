<?php

declare(strict_types=1);

namespace Baander\Core;

/** Shared names only; each consumer owns its defaults, validation and admission policy. */
final class EnvironmentVariable
{
    public const string APP_ENV = 'APP_ENV';
    public const string APP_DEBUG = 'APP_DEBUG';
    public const string APP_HOME = 'APP_HOME';
    public const string APP_SECRET = 'APP_SECRET';
    public const string APP_URL = 'APP_URL';
    public const string APP_DOMAIN = 'APP_DOMAIN';
    public const string DEFAULT_URI = 'DEFAULT_URI';
    public const string BAANDER_DEPLOYMENT_PROFILE = 'BAANDER_DEPLOYMENT_PROFILE';
    public const string BAANDER_DEV_ORIGIN = 'BAANDER_DEV_ORIGIN';
    public const string BAANDER_DEVELOPMENT_INSTANCE = 'BAANDER_DEVELOPMENT_INSTANCE';
    public const string TRUSTED_PROXIES = 'TRUSTED_PROXIES';

    public const string DATABASE_URL = 'DATABASE_URL';
    public const string DATABASE_CONTROL_URL = 'DATABASE_CONTROL_URL';
    public const string REDIS_URL = 'REDIS_URL';
    public const string REDIS_PASSWORD = 'REDIS_PASSWORD';
    public const string MESSENGER_TRANSPORT_DSN = 'MESSENGER_TRANSPORT_DSN';
    public const string MAILER_DSN = 'MAILER_DSN';
    public const string MAIL_FROM_ADDRESS = 'MAIL_FROM_ADDRESS';
    public const string CONVERT_STORAGE_PATH = 'CONVERT_STORAGE_PATH';
    public const string MEDIA_STORAGE_PATH = 'MEDIA_STORAGE_PATH';
    public const string VIDEO_ENCODER = 'VIDEO_ENCODER';
    public const string TMDB_BEARER_TOKEN = 'TMDB_BEARER_TOKEN';

    public const string AUTH_TOTP_ENCRYPTION_KEY = 'AUTH_TOTP_ENCRYPTION_KEY';
    public const string OAUTH_PRIVATE_KEY_PATH = 'OAUTH_PRIVATE_KEY_PATH';
    public const string OAUTH_PUBLIC_KEY_PATH = 'OAUTH_PUBLIC_KEY_PATH';
    public const string STREAM_URL_HMAC_SECRET = 'STREAM_URL_HMAC_SECRET';
    public const string VAPID_PRIVATE_KEY = 'VAPID_PRIVATE_KEY';
    public const string VAPID_PUBLIC_KEY = 'VAPID_PUBLIC_KEY';
    public const string OTEL_ENABLED = 'OTEL_ENABLED';
    public const string OTEL_EXPORTER_OTLP_ENDPOINT = 'OTEL_EXPORTER_OTLP_ENDPOINT';

    public const string BAANDER_LOG_AGENT_CONFIG = 'BAANDER_LOG_AGENT_CONFIG';
    public const string BAANDER_LOG_DIR = 'BAANDER_LOG_DIR';
    public const string BAANDER_LOG_NODES = 'BAANDER_LOG_NODES';

    public const string BAANDER_POOLER_USER = 'BAANDER_POOLER_USER';
    public const string BAANDER_POOLER_DATABASE = 'BAANDER_POOLER_DATABASE';
    public const string BAANDER_POOLER_SERVER_HOST = 'BAANDER_POOLER_SERVER_HOST';
    public const string BAANDER_POOLER_APPLICATION_POOL_SIZE = 'BAANDER_POOLER_APPLICATION_POOL_SIZE';
    public const string BAANDER_POOLER_CONTROL_POOL_SIZE = 'BAANDER_POOLER_CONTROL_POOL_SIZE';

    public const string BAANDER_WORKER_NAMESPACE = 'BAANDER_WORKER_NAMESPACE';
    public const string BAANDER_WORKER_BOOT_ID = 'BAANDER_WORKER_BOOT_ID';
    public const string BAANDER_WORKER_ID = 'BAANDER_WORKER_ID';
    public const string BAANDER_WORKER_UNIT = 'BAANDER_WORKER_UNIT';
    public const string BAANDER_WORKER_GENERATION = 'BAANDER_WORKER_GENERATION';
    public const string BAANDER_WORKER_LEASE_EPOCH = 'BAANDER_WORKER_LEASE_EPOCH';
    public const string BAANDER_WORKER_AUTOSCALING_PROFILE = 'BAANDER_WORKER_AUTOSCALING_PROFILE';
    public const string BAANDER_WORKER_IPC_DIRECTORY = 'BAANDER_WORKER_IPC_DIRECTORY';
    public const string BAANDER_NATIVE_SOCKET = 'BAANDER_NATIVE_SOCKET';
    public const string BAANDER_NATIVE_CLIENT_ID = 'BAANDER_NATIVE_CLIENT_ID';
    public const string BAANDER_NATIVE_CLIENT_GENERATION = 'BAANDER_NATIVE_CLIENT_GENERATION';
    public const string BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES = 'BAANDER_SCHEDULED_CONSOLE_RESERVATION_BYTES';
    public const string MESSENGER_CONSUMER_NAME = 'MESSENGER_CONSUMER_NAME';
}
