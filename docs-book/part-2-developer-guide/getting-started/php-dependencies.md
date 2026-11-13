# PHP dependency maintenance

After changing a Composer manifest, lock, runtime recipe or local dependency fix,
run the dependency checker before deciding that the stack is current. It separates
source and lock consistency from upstream currency and from runtime qualification.
The selected stack is PHP 8.5.12, Swoole 6.2.4 and Composer 2.11.1. Recipes retain
their intended ZTS or NTS mode; matching version declarations alone does not prove
that a rebuilt image has the required native behavior.

## Projects and locks

The [dependency policy](../../../packages/baander-tooling/resources/php-dependencies.json)
classifies all seven owned Composer projects. Discovery reports newly added source
manifests that have no classification.

| Manifest | Dependency ownership |
| --- | --- |
| `composer.json` | Application lock, including development packages |
| `scripts/composer.json` | Standalone tooling loader lock |
| `relay/composer.json` | Registry qualification loader lock |
| `packages/tsduck-php-ffi/composer.json` | TSDuck package lock and independent tests |
| `packages/baander-tooling/composer.json` | Local package consumed by root and scripts locks |
| `packages/baander-phpstan-rules/composer.json` | Local package consumed by the root lock |
| `packages/swoole-bundle/composer.json` | Local fork consumed by the root lock |

Consumer locks do not resolve a dependency package's own `require-dev` section.
Inspect the PHPStan rules and Swoole bundle development requirements separately,
using disposable copies for independent Composer resolution and package tests.
Keep those resolutions outside the checkout rather than adding library locks.
The 2026-10-31 constraint audit included every manifest's production and development
requirements: each public direct constraint admitted its latest stable release.
Constraint eligibility alone does not establish a compatible full development set.

## Repeatable checks

Install the [standalone loader](../../../packages/baander-tooling/README.md)
and put PHP 8.5 or newer with Swoole and exactly Composer 2.11.1 on `PATH`.
Run these commands from the repository root:

```bash
composer run-script --timeout=720 check:php-dependencies:local
composer run-script --timeout=720 check:php-dependencies
php scripts/check-php-dependencies.php --local-only --format json
php scripts/check-php-dependencies.php --format json
```

Local mode validates all seven manifests, reads both package sections of each owned
lock, compares runtime declarations, and checks patch metadata and the fork's
reviewed source hashes. Missing vendor metadata does not remove locked development
packages from the inventory. Local output explicitly excludes online currency,
advisories and registry image metadata.

Online mode compares independent latest stable releases for every direct requirement
and locked package, including indirect and development dependencies. It also collects
locked Composer audit and outdated evidence, runtime releases, and patch/fork upstream references. It needs
HTTPS-capable curl, upstream access and the configured Docker target with Buildx
registry inspection. A release remains visible when the host platform, declared
constraint or local path repository prevents Composer from selecting it.

| Status | Meaning |
| --- | --- |
| `0` | Requested checks completed without findings; local exclusions still apply. |
| `1` | Requested checks completed with findings. |
| `2` | Requested evidence is incomplete; earlier findings remain visible. |

The checker disables Composer plugins and scripts, isolates Composer settings and
cache, and does not install, update, relock or apply patches. Neither mode proves
clean patch application, installed extension versions or native runtime behavior.
See the [tooling command reference](../../../packages/baander-tooling/README.md#php-dependency-maintenance)
for budgets, environment handling and required interfaces. CI uses local mode;
online assessment remains an explicit maintenance action.

Composer's outdated view uses the maintenance host's extensions. A missing extension
can make its candidate older than the locked version: the 2026-10-31 host lacked
Sodium, so Composer reported JWT 4.0.4 against locked 5.6.1. Independent release
metadata confirmed 5.6.1 was current, and platform checks passed in the application
image. Read platform diagnostics alongside the independent comparison; a lower
host-selected candidate is not evidence that the application needs a downgrade.

## Remaining stable-release blockers

On 2026-10-31, fresh authoritative Packagist metadata for 242 public packages was
compared with all four locks, including development packages. Composer 2.11.1
`why-not --locked` confirmed the blockers below against copies of those locks.
The scripts, relay and TSDuck locks had no packages behind latest stable.

| Root locked package | Locked | Latest stable | Blocking requirement |
| --- | --- | --- | --- |
| [monolog/monolog](https://repo.packagist.org/p2/monolog/monolog.json) | 3.10.0 | 3.12.1 | reli-prof requires exactly 3.10.0 |
| [webmozart/assert](https://repo.packagist.org/p2/webmozart/assert.json) | 2.3.0 | 2.5.0 | reli-prof requires exactly 2.3.0 |
| [amphp/amp](https://repo.packagist.org/p2/amphp/amp.json) | 3.1.1 | 3.1.3 | reli-prof requires exactly 3.1.1 |
| [amphp/parallel](https://repo.packagist.org/p2/amphp/parallel.json) | 2.3.3 | 2.4.0 | reli-prof requires exactly 2.3.3 |
| [symfony/monolog-bundle](https://repo.packagist.org/p2/symfony/monolog-bundle.json) | 4.0.2 | 4.1.0 | Latest requires Monolog ^3.12, conflicting with the profiler pin |

[reli-prof 0.12.0's manifest](https://github.com/reliforp/reli-prof/blob/22ef419d0670cd257fdf7970efafbe0fa09464ae/composer.json)
owns these exact requirements; 0.12.0 was also its
[latest stable release](https://repo.packagist.org/p2/reliforp/reli-prof.json).
The root constraints permit the newer stable packages. Recheck the profiler's
requirements when upgrading it; raising root minimums cannot resolve these
conflicts. This dated currency assessment does not establish that every rebuilt
image or application qualification gate has passed. Current acceptance evidence
belongs in [ROADMAP.md](../../../ROADMAP.md).

## Local fixes and native pins

The Swoole bundle remains a path fork, reviewed against upstream v0.35.6 at
`81f35b1fac4f7df2a173b6ae83a194e2ad6d8047`. Its local source inventory and
decisions are recorded in the [bundle audit](../../../packages/swoole-bundle/upstream-review.json)
and [maintenance notes](../../../packages/swoole-bundle/README.md#baander-fork-maintenance).
A changed upstream reference requests another review; matching metadata does not
prove that the local behavior is unnecessary.

The exact z-engine 8.5.2 selection retains the PHP 8.5 ZTS initialization patch.
Swoole runtime declarations, platform metadata and stubs agree on 6.2.4; their
agreement does not establish the installed extension version. The Redis Messenger
patch retains bounded, recoverable delayed promotion on Symfony 8.1.10. See the
[patch guide](../../../patches/README.md) for upstream references, native limits and
the owning regressions. Preserve those proofs when changing a pin or removing a
patch.

The unused reflection fork was removed after tracing callers and registration:
no external consumers or autoload registration side effects required it. It is
not an active fork exception. Clean autoload and generated-container qualification
remain separate checks from that source trace.

## TSDuck qualification blocker

The package's independent PHPUnit configuration requires a real FFI library and
fails if native prerequisites are missing. Run it inside the selected CI runtime,
after installing the package's own locked development dependencies:

```bash
TSDUCK=/usr/lib php -d ffi.enable=true packages/tsduck-php-ffi/vendor/bin/phpunit \
  --configuration packages/tsduck-php-ffi/phpunit.xml.dist
```

The 2026-10-31 run fails during bootstrap: the bindings declare `tspyphp*` polling
functions that the upstream TSDuck v3.43-4549 library does not export. Neither the
Docker build inputs nor repository history contain the C++ bridge implementation.
The Composer update does not repair that gap. Native TSDuck qualification and its
automatic CI gate remain blocked pending a separately scoped bridge implementation;
missing functions must not be hidden by skipping tests or invoking PHP callbacks
from native background threads.
