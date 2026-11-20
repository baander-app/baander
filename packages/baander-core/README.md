# Baander Core

`baander/core` provides [environment variable names](src/EnvironmentVariable.php)
and [configuration paths](src/Configuration/ConfigPath.php) shared by the application
and its operational and development packages. It requires
PHP 8.5 and has no other dependencies, as declared in [composer.json](composer.json).

Use a named constant where PHP reads, writes or validates an environment key:

```php
use Baander\Core\EnvironmentVariable;

$environment = getenv(EnvironmentVariable::APP_ENV);
```

The catalog defines spelling only. Consumers retain their own defaults, value
validation and lists of admitted keys. In particular, a worker identity name in
the catalog does not permit an operator to pass it through the application runtime
environment. Shell scripts and configuration files keep their literal names.

## Configuration paths

`ConfigPath` reads a decoded configuration array through immutable, literal key and
index segments. Each call returns a new path; a dot in a key is not a separator.

```php
use Baander\Core\Configuration\ConfigPath;

$path = ConfigPath::root()->key('workers')->index(0)->key('slots');
$slots = $path->integer($configuration);
```

`key()` accepts a string or a string-backed enum, and `index()` accepts a
nonnegative integer. `value()` returns the value at a path, including `null`;
`integer()` requires an actual integer and does not coerce strings. Missing keys,
non-array parents and type mismatches raise an exception whose diagnostic omits
the configuration value. The path does not read environment variables or supply
defaults: the owning context defines names, policies and defaults.

Package tests live in `tests/` and run with the shared root tooling runner. See the
[workflow reference](../../dev-docs/workflow-packages.md#checks) for commands.
