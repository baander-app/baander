# Dev docker services

## Docker networks

Currently only `baander-backtier` is defined. This network is used for connecting all containers.

You must __not__ define/create this network. It will be created automatically.

## Redis

__Host__:

Memory store for caching and queue services

Bound to host 127.0.0.1:6379

Docker network baander-backtier as hostname redis

No password

Recommendation: Use [Redis insight](https://redis.io/insight/)

## Postgres

Database for storing everything important e.g. Libraries and Users.

__Host__:

Bound to host 127.0.0.1:5432 (not available outside localhost)

Docker network baander-backtier as hostname postgres

__Username:__ baander

__Password:__ baander

__Database:__ baander

Recommendation: Use [Beekeeper Studio](https://www.beekeeperstudio.io/) the free tier is more than enough.

If you can get a license for Navicat, then that's also a solid choice.

## Mailpit

Only used for testing emails in development

Bound to host:

__127.0.0.1:8025__ The smtp server. Probably not something you will use, but its available.

__127.0.0.1:1025__ The web interface for viewing emails.