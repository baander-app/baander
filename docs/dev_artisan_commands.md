# Development artisan commands

These commands are used during development. They should not be used in production.

## artisan dev:server

Spaws a local development server, queue worker and scheduler. All services are run with hot reloading enabled.

## artisan setup:dev

`php artisan setup:dev --fresh` will drop and re-create the database.

If no .env file eixsts, this command will create one from .env.example.

Runs all migrations and seeds test users.

## artisan prune:libraries

Prune all libraries.

## artisan make:log-channel

Create logging channels with specified types and a stack channel that combines them all.

{name} {--types=* : The channel types to create (file, otel, daily)}
