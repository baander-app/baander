# Bånder

## What Bånder is

Bånder is a self-hosted server for organizing and playing a personal media collection.
It keeps your music and movies in a library you can browse through a web interface.

## What you can do

- Browse your collection by artist, album, song or movie.
- Enrich your library with metadata and artwork from external providers, with
  credentials configured for the services you use.
- Search your catalog and organize songs into playlists.
- Play music in the web player and stream video, with transcoding for supported
  playback formats.

## Getting started

Follow the **[installation guide](docs-book/part-1-operator-guide/getting-started.md)**
for configuration, storage, account setup and your first library.

The current setup builds from source and uses a development-oriented Docker Compose
stack. Background processing requires a separate worker deployment; starting
Compose alone does not provide a complete instance. The installation guide includes
host requirements and links to the worker deployment procedure.

## For developers

Start with the [developer guide](docs-book/part-2-developer-guide/README.md),
[development environment](docs-book/part-2-developer-guide/development-environment.md)
and [contribution instructions](docs-book/part-2-developer-guide/contributing.md).

## License

Baander's first-party code is licensed under [Apache-2.0](LICENSE.md), with
[package-specific exceptions and third-party notices](THIRD_PARTY.md).
Copyright (C) 2025–2026 Martin Juul Christiansen and contributors.
