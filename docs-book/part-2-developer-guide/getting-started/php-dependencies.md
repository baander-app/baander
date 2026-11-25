# PHP dependency maintenance

After changing a Composer manifest, lock, runtime recipe or local dependency fix,
run the dependency checker before deciding that the stack is current. It separates
source and lock consistency from upstream currency and from runtime qualification.
The selected stack is PHP 8.5.12, Swoole 6.2.4 and Composer 2.11.1. Recipes retain
their intended ZTS or NTS mode; matching version declarations alone does not prove
that a rebuilt image has the required native behavior.

## Projects and locks

The [dependency policy](../../../packages/baander-dev-dependencies/resources/php-dependencies.json)
classifies all seven owned Composer projects. Discovery reports newly added source
manifests that have no classification.

| Manifest | Dependency ownership |
| --- | --- |
| `composer.json` | Application lock, including development packages |
| `scripts/composer.json` | Standalone tooling loader lock |
| `relay/composer.json` | Registry qualification loader lock |
| `packages/tsduck-php-ffi/composer.json` | TSDuck package lock and independent tests |
| `packages/baander-*/composer.json` | Local workflow and core packages consumed by root and scripts locks |
| `packages/baander-phpstan-rules/composer.json` | Local package consumed by the root lock |
| `packages/swoole-bundle/composer.json` | Local fork consumed by the root lock |

Consumer locks do not resolve a dependency package's own `require-dev` section.
Inspect the PHPStan rules and Swoole bundle development requirements separately,
using disposable copies for independent Composer resolution and package tests.
Keep those resolutions outside the checkout rather than adding library locks.
The 2026-10-31 constraint audit included every manifest's production and development
requirements: each public direct constraint admitted its latest stable release.
Constraint eligibility alone does not establish a compatible full development set.

`composer.json` and `scripts/composer.json` set `minimum-stability` to `stable`.
Each local path package is required with an explicit `@dev` flag, so only those
packages may resolve to a development version. Composer reads stability flags from
the root manifest alone, so a path package reached only through another path
package fails the stable minimum. `scripts/composer.json` therefore requires
`baander/configuration`, `baander/containers`, `baander/core`,
`baander/filesystem` and `baander/process` directly, although other path packages
already require them. Without those five entries, Composer 2.11.1 stopped on
2026-11-24 with "found baander/configuration[dev-main] but it does not match your
minimum-stability".

## Repeatable checks

Install the [standalone loader](../../../dev-docs/workflow-packages.md)
and put PHP 8.5 or newer with Swoole and exactly Composer 2.11.1 on `PATH`.
Run these commands from the repository root:

```bash
composer run-script --timeout=720 check:php-dependencies:local
composer run-script --timeout=720 check:php-dependencies
composer run-script --timeout=720 check:php-dependencies:advisories
php scripts/check-php-dependencies.php --local-only --format json
php scripts/check-php-dependencies.php --format json
php scripts/check-php-dependencies.php --advisories-only --format json
```

Local mode validates all classified manifests, reads both package sections of each owned
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

Advisories-only mode runs `composer audit --locked` on each owned lock and assesses
nothing else: it skips validation, currency, runtime, patch and fork evidence. It
needs Composer 2.11.1 and HTTPS access to Packagist, but neither curl nor Docker. A
security advisory fails it, and so does a match with one of Composer's policy lists,
such as its malware list. Abandoned packages, advisories that a project's Composer
configuration ignores and releases inside a configured cooldown period appear in the
report without failing it. An outdated package does not fail it either, because the
mode never asks for newer releases.

A lock whose audit did not complete is incomplete evidence, so advisories-only mode
exits `2` when Packagist is unreachable, when Composer cannot run or when it prints
nothing. On 2026-11-24, Composer 2.11.1 without network access exited `100` with
"curl error 6 while downloading https://repo.packagist.org/packages.json" and no
report; the checker reads that as status `2`. The relay lock is the one refusal it
accepts. Composer exits `1` on that lock with "No installed packages found" instead
of a report. The checker then reads the lock itself and accepts the refusal only
when both package lists are empty.

| Status | Meaning |
| --- | --- |
| `0` | Requested checks completed without findings; local exclusions still apply. |
| `1` | Requested checks completed with findings. |
| `2` | Requested evidence is incomplete; earlier findings remain visible. |

The checker disables Composer plugins and scripts, isolates Composer settings and
cache, and does not install, update, relock or apply patches. No mode proves
clean patch application, installed extension versions or native runtime behavior.
See the [tooling command reference](../../../dev-docs/workflow-packages.md#php-dependency-maintenance)
for budgets, environment handling and required interfaces.

CI runs local mode on every push to `master` and every pull request. The
[Dependency advisories workflow](../contributing/continuous-integration.md#dependency-advisories)
runs advisories-only mode every day and on manual dispatch. The full online
assessment remains an explicit maintenance action: it reports every package behind
its latest stable release as a finding, so it cannot pass while the blockers listed
[below](#remaining-stable-release-blockers) hold seven root packages back.

Composer's outdated view uses the maintenance host's extensions. A missing extension
can make its candidate older than the locked version: the 2026-10-31 host lacked
Sodium, so Composer reported JWT 4.0.4 against locked 5.6.1. Independent release
metadata confirmed 5.6.1 was current, and platform checks passed in the application
image. Read platform diagnostics alongside the independent comparison; a lower
host-selected candidate is not evidence that the application needs a downgrade.

## Remaining stable-release blockers

On 2026-11-24 a full `composer update` moved the root, scripts and TSDuck locks to
the newest releases Composer could select. Composer 2.11.1 `outdated --locked`,
run afterwards in the application image, listed seven root packages behind their
latest stable release, and `why-not --locked` named the requirement that holds each.
The scripts and TSDuck locks had no packages behind latest stable; the relay lock
holds no packages.

| Root locked package | Locked | Latest stable | Blocking requirement |
| --- | --- | --- | --- |
| [monolog/monolog](https://repo.packagist.org/p2/monolog/monolog.json) | 3.10.0 | 3.13.0 | reli-prof requires exactly 3.10.0 |
| [webmozart/assert](https://repo.packagist.org/p2/webmozart/assert.json) | 2.3.0 | 2.5.1 | reli-prof requires exactly 2.3.0 |
| [amphp/amp](https://repo.packagist.org/p2/amphp/amp.json) | 3.1.1 | 3.1.3 | reli-prof requires exactly 3.1.1 |
| [amphp/parallel](https://repo.packagist.org/p2/amphp/parallel.json) | 2.3.3 | 2.4.0 | reli-prof requires exactly 2.3.3 |
| [symfony/monolog-bundle](https://repo.packagist.org/p2/symfony/monolog-bundle.json) | 4.0.2 | 4.1.0 | Latest requires Monolog ^3.12, conflicting with the profiler pin |
| [jetbrains/phpstorm-stubs](https://repo.packagist.org/p2/jetbrains/phpstorm-stubs.json) | 2026.2 | 2026.3 | Deptrac 4.7.2 requires 2024.3, 2025.3, 2026.1 or 2026.2 |
| [swoole/ide-helper](https://repo.packagist.org/p2/swoole/ide-helper.json) | 6.2.4 | 6.2.5 | The root manifest requires exactly 6.2.4, the selected Swoole version |

[reli-prof 0.12.0's manifest](https://github.com/reliforp/reli-prof/blob/22ef419d0670cd257fdf7970efafbe0fa09464ae/composer.json)
owns the exact requirements behind the first five rows; 0.12.0 was also its
[latest stable release](https://repo.packagist.org/p2/reliforp/reli-prof.json).
The root constraints permit the newer stable packages. Recheck the profiler's
requirements when upgrading it; raising root minimums cannot resolve these
conflicts. Deptrac 4.7.2 was also its latest stable release, so no Deptrac update
admits the newer PhpStorm stubs. The `swoole/ide-helper` stubs stay at the selected
Swoole version; see [Local fixes and native pins](#local-fixes-and-native-pins).
This dated currency assessment does not establish that every rebuilt
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
patch retains bounded, recoverable delayed promotion on Symfony 8.1.11. See the
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
