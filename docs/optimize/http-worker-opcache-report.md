# HTTP-worker opcode-cache memory

The isolated preview reported roughly 739 MiB of PHP heap across four HTTP
workers on 2026-11-20. Its image had `opcache.enable_cli=0`. The server runs
through CLI PHP, so the installed OPcache extension did not cache its code.

The old Compose configuration mounted `docker/dev/php.ini`, which enabled CLI
OPcache. Standalone production began using the stock production INI in
`246779857`; isolated development images began using the stock development INI
in `a484a0832`. Neither carried the opcode-cache settings. This omission predates
the tooling package split.

## Configuration and checks

`docker/php/opcache.ini` now owns the existing opcode-cache settings, and the
common runtime image installs it in `conf.d`. Development, production, worker,
operator and CI targets inherit it. The duplicate block in the development INI
is removed. The cache retains its 256 MiB capacity, 32 MiB interned-string buffer
and 20,000-script setting. Timestamp checks remain enabled; already-loaded
classes still require the normal worker refresh. PHP memory limits and worker
counts are unchanged.

The Docker build compiles a temporary PHP script and checks that OPcache cached
it. The previous preview image fails the enabled-cache check. A separate smoke
probe also rewrites the script and checks that PHP observes the changed return
value without disabling the cache. That [image probe](http-worker-opcache/image-probe.php)
passes in the rebuilt development, production-derived worker and CI images without
configuration mounts or command-line overrides. All retain `memory_limit=512M`;
the [image checks](http-worker-opcache/image-checks.json) record their identities
and effective settings.

## Controlled kernel comparison

The retained [boot probe](http-worker-opcache/boot-probe.php) runs in a disposable,
network-disabled container with synthetic secrets and a separate cache directory.
Each pair starts two PHP processes: the first compiles the Symfony container,
and the second uses that compiled container. All eight executions passed on
PHP 8.5.12 and Swoole 6.2.4 in the CI image. These are development and production
kernel modes with the same dependency installation, not two different images.

The [measurements](http-worker-opcache/boot-measurements.json) record the image,
probe hash, command and raw figures after cycle and allocator-cache collection.

| Kernel mode | Container cache | Heap without OPcache | Heap with OPcache |
|---|---|---:|---:|
| Development | Cold | 147.14 MiB | 26.92 MiB |
| Development | Warm | 17.14 MiB | 5.22 MiB |
| Production | Cold | 140.84 MiB | 25.67 MiB |
| Production | Warm | 13.80 MiB | 4.38 MiB |

Cold compilation loads 7,792 classes in development and 7,564 in production.
The warm processes load 765 and 703 respectively. Garbage collection can release
the compiler's object graph, but cannot unload those class definitions. OPcache
moves much of that compiled code out of the process heap.

The opcode cache occupies about 137 MiB for the cold development boot and
133 MiB for production. Single-process proportional resident memory (PSS) rises
with OPcache in these probes: that process owns the populated cache. A lower PHP
heap figure alone therefore does not establish a physical-memory saving.

Four further [native-image boots](http-worker-opcache/image-boots.json) pass using
the rebuilt images' own dependencies and configuration. Development uses
26.96 MiB cold and 5.22 MiB warm; the production-derived worker uses 25.58 MiB cold
and 4.26 MiB warm. The worker target inherits the production filesystem and PHP
configuration, changing only its launch and health-check settings.

## Running preview

The [baseline](http-worker-opcache/preview-before.json) records 735.32 MiB of
summed worker heap and 560.42 MiB of PSS across all six PHP server processes.
The four workers reported 179.84–186.91 MiB each. The admin dashboard was polling
the server; this was an observation of the existing session, not a controlled
request replay.

The preview refresh completed successfully and its public URL returned HTTP 200.
Browser verification logged in, loaded the media home page and opened the admin
dashboard. After 60 seconds of dashboard polling, its memory card showed
173.31 MB. The immediately following diagnostics sample reported 173.19 MiB of
combined worker heap. All four workers answered.

| Sample | Summed worker heap | PSS of all six PHP processes |
|---|---:|---:|
| Before refresh | 735.32 MiB | 560.42 MiB |
| New server, before dashboard traffic | 138.45 MiB | 344.90 MiB |
| After browser use and polling | 173.19 MiB | 444.58 MiB |

The [startup sample](http-worker-opcache/preview-after-startup.json) and
[settled sample](http-worker-opcache/preview-after-settling.json) retain process
identities and per-worker figures. The final four workers used
38.44, 41.21, 52.60 and 40.94 MiB. The cache settings are active in the rebuilt
image; the existing periodic pool maintenance remains unchanged.

The original container reported 5,579 seconds of uptime, versus 157 seconds in
the settled sample. A restart and a shorter request history also affect the
live comparison. The controlled kernel comparison isolates the effect on PHP
heap; the live samples establish the current footprint, not how many physical
bytes OPcache alone saved or whether memory remains bounded over a long session.
The dashboard sums PHP heap in MiB despite its MB label; it does not report PSS
or include the master and manager processes.
