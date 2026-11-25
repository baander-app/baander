# Investigating logs

Open **Admin → Logs** to search the services in a Baander deployment together. The page requires a super administrator. The terminal commands have the same full-authority access as other administrative commands.

The source list includes container streams, application files, rotated files and admitted gzip or ZIP members. Select sources, enter a literal search, or filter by level, channel, correlation ID and time. The Channel selector lists the channels retained by the available agents. Select several to match any of them, or clear the selection to include all channels. Selections and date filters apply immediately; search and correlation-ID text apply after a short typing pause, or immediately with Enter. 

All time searches retained history without date bounds. Time-range shortcuts move with live follow; manual dates select Custom. 

Sources are grouped by service, with a count beside each group name. Unavailable sources sit in a collapsed group, and their retained records remain selectable. Expand Collection issues to see which source or collection host has a problem. The message list and SQL results have their own compact issue notices; expanded details scroll within a bounded area. 

Select a message to expand it below the list. The full message appears immediately; raw text, structured context and metadata are also available in that pane. Pause keeps the current view while collection continues; resume returns to current records. Older pages belong to the snapshot that started that search.

The registry is a separate system and is not included in this deployment's collection.

## Terminal use

Run the commands inside the installation's application container. The interactive viewer needs a terminal; piping its output produces a plain response instead.

```bash
php bin/console app:log:sources --json
php bin/console app:log:view
php bin/console app:log:view --plain --level ERROR --limit 50
php bin/console app:log:query 'SELECT level, count(*) FROM logs GROUP BY level' --json
```

Use `/` to search, `s` to select sources, `f` to filter levels, `c` to select comma-separated channels, `F` to toggle follow and `:` to enter SQL. `?` shows the controls. `q`, Ctrl-C and Ctrl-D exit and restore the terminal. The [viewer reference](../commands/app-log-view.md) lists every filter and key; [SQL commands](../commands/app-log-query.md) describe scriptable queries.

## Sources and collection

Generated preview and preset configurations install a `log-agent` service. It checks the captured Docker daemon identity and admits only the deployment's configured service labels. Web, worker, gateway, PostgreSQL, Redis and pooler streams are collected where those services are configured. Application file logs use retained service and worker-boot directories; removing a worker container does not remove its log directory.

The agent reaches Docker only through the `docker-proxy` service, an nginx from the gateway image. The proxy alone mounts the daemon's socket; it joins no network, publishes no port and shares its own socket with the agent alone. It passes `GET` on `/info`, `/containers/json`, `/events`, `/containers/{id}/json` and `/containers/{id}/logs` and answers `403` to every other method and path, so the agent cannot create, start, change or execute in a container. Container inspect still returns each container's environment, `APP_SECRET` included, to the agent. The web container receives a query credential and the agent endpoint. Query and final-checkpoint credentials are separate. Worker retirement stops the worker, requests a bounded final collection checkpoint, then removes the container. A failed checkpoint leaves a collection-gap record. See the deployment wiring in [LogCollection](../../../packages/baander-deployment/src/Observability/LogCollection.php) and [worker containment](../../../src/Shared/Infrastructure/Worker/DockerWorkerContainment.php).

For a separately managed agent, `BAANDER_LOG_AGENT_CONFIG` contains trusted JSON or the path to its JSON file. The configuration names the node, index path, credential files, admitted file roots and Docker ownership groups. Docker collection also requires the expected daemon identity. Run `php bin/log-agent.php` with this configuration and retain its writable index volume. Mount source directories read-only. The [agent configuration](../../../src/Shared/Infrastructure/LogViewer/Agent/AgentConfiguration.php) and [generated service](../../../packages/baander-deployment/src/Observability/LogCollection.php) define the supported fields and mounts.

`BAANDER_LOG_NODES` configures the application's query endpoints as a JSON list of `id`, `url` and `tokenFile` values. Additional hosts require an agent, a mounted read credential and an HTTPS endpoint trusted by the application. Request parameters cannot supply an endpoint or filesystem path. The generated local endpoint uses the private Compose network; it is not published to the host. Ordinary restart and down operations retain log data.

## Coverage and retention

Read the coverage indicator before treating an empty result as evidence that nothing happened. A disconnected node, expired snapshot, source error or collection gap makes coverage incomplete. Docker log timestamps do not provide durable byte offsets: when rotation makes a replay boundary ambiguous, the collector retains uncertain records with replay metadata and reports the gap.

Production logs at `info`; debug records are not written. Every preset service writes its output through Docker's `local` log driver, at most five files of 50 MB per container, and older output is gone from the daemon once it rotates out. The web server and each worker boot write their files under a directory of their own on the `logs` volume. Each preset `start` removes the boot directories whose newest file is more than 14 days old, keeping each service's two most recent, the current boot and the previous one, whatever their age.

The agent defaults to seven days of indexed records and a 1 GiB index budget. Original application files are not deleted by index pruning. If an active reader prevents storage reclamation, collection reports the storage failure rather than allowing the index to grow without a bound. Retention and physical-storage enforcement are implemented by [LogIndex](../../../src/Shared/Infrastructure/LogViewer/LogIndex.php).

A correlation ID from the `X-Baander-Correlation-ID` header longer than 512 bytes, or with a control character, is replaced by a generated one, and the request URL and referrer a record carries are cut to 2,048 bytes. A retained record is limited to 256 KiB and carries truncation metadata. Archive import permits at most 1,000 ZIP members and 256 MiB of expanded data per archive. The reader rejects nested or encrypted archives, unsafe members and corrupt streams; concatenated gzip members are not supported. These failures remain source errors. Admission and expansion limits are enforced by [ArchiveReader](../../../src/Shared/Infrastructure/LogViewer/ArchiveReader.php).

## SQL snapshots

The `logs` table contains the selected normalized records. Columns include `timestamp`, `level`, `channel`, `message`, `raw`, `source_id`, `correlation_id`, `node`, `service`, `stream`, `context` and `extra`; structured fields are JSON text. Filters select the input before SQL runs. CTEs, aggregation, joins and window functions are supported.

SQL reads a private snapshot, limited to 100,000 records or 64 MiB. It refuses incomplete input unless **Allow partial** or `--allow-partial` is selected. Every result retains its coverage information. A query can return at most 1,000 rows and 4 MiB, and its child process has a five-second execution deadline. Reaching a result limit is visible. Writes, attachments, extension loading and filesystem/network functions are rejected. The query does not open application databases. See [SQL execution](../../../src/Shared/Infrastructure/LogViewer/Sql/SqlQueryRunner.php) and [statement authorization](../../../src/Shared/Infrastructure/LogViewer/Sql/SnapshotQueryEngine.php).

## Troubleshooting

An unavailable node remains in the source inventory. Check its agent process, configured endpoint, TLS trust and mounted query credential. An empty node configuration has no sources to search. A rejected cursor requires a new search because its filters changed or retained history expired.

For a file that has just received a multiline message, collection waits for its next record or a short period without further writes. A later continuation remains available as a linked fragment. Signed `sig`, `token` and `ticket` URL values are redacted in retained records, and both viewers render log content as text. See [normalization](../../../src/Shared/Infrastructure/LogViewer/LogNormalizer.php) and [collection](../../../src/Shared/Infrastructure/LogViewer/Agent/Collector.php).
