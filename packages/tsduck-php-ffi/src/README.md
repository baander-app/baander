# TSDuck PHP Bindings

PHP FFI bindings for [TSDuck](https://tsduck.io/) — the MPEG Transport Stream Toolkit. Provides access to the full
TSDuck C++ library from PHP, including TS processing, section file manipulation, input switching, system monitoring, and
plugin event handling.

## Requirements

- **PHP 8.0+** (64-bit build required for plugin event handling)
- **FFI extension** enabled (`ext-ffi`)
- **libtsduck** shared library installed (minimum version 3.38-3838)

### Enabling FFI

**CLI (PHP scripts):** PHP must be compiled with `--enable-ffi`. Most distributions include this by default.

**Web SAPI (Apache/Nginx):** Set in `php.ini`:

```ini
ffi.enable = true
```

Or use the environment variable:

```bash
PHP_FFI_ENABLE=1 php script.php
```

### Installing libtsduck

See [https://tsduck.io/download](https://tsduck.io/download) for platform-specific installation instructions.

## Installation

Install via Composer:

```bash
composer require tsduck/php
```

Or copy the `php/src/` directory and register the autoloader manually.

## Quick Start

### Basic TS Processing

Process an MPEG-TS file through a plugin chain:

```php
<?php

use Tsduck\TSProcessor;
use Tsduck\Report\StdErrReport;

$report = StdErrReport::getInstance();

$tsp = new TSProcessor($report);
$tsp->setPlugins('-I', 'file', 'input.ts', '-O', 'file', 'output.ts');
$tsp->start();
$tsp->waitForTermination();
$tsp->close();
```

### Section File Manipulation

Load, inspect, and convert MPEG-TS sections:

```php
<?php

use Tsduck\DuckContext;
use Tsduck\SectionFile;
use Tsduck\Report\StdErrReport;

$duck = new DuckContext(StdErrReport::getInstance());
$sf = new SectionFile($duck);

$sf->loadBinary('sections.bin');
echo "Sections: {$sf->sectionsCount()}, Tables: {$sf->tablesCount()}\n";
echo "Binary size: {$sf->binarySize()} bytes\n";

$xml = $sf->toXML();
echo $xml;

$sf->saveJSON('sections.json');

$sf->close();
$duck->close();
```

### Library Version Check

```php
<?php

use Tsduck\Info;

echo Info::version() . "\n";     // e.g. "TSDuck - Version 3.38-3838"
echo Info::intVersion() . "\n";  // e.g. 32702383
```

## Architecture Overview

The PHP bindings use FFI to call C wrapper functions (`tspy*` API) in the native `libtsduck` shared library. All native
objects are managed by the `NativeObject` base class, which provides:

- **Opaque pointer storage** — each PHP object wraps a C++ object via an opaque `void*`
- **Deterministic cleanup** — call `close()` explicitly, or rely on the destructor as a safety net
- **Thread-safe polling** — log messages and plugin events from C++ threads are queued and polled from PHP (no unsafe
  FFI callbacks from non-PHP threads)

### Class Hierarchy

```
NativeObject
├── DuckContext
├── SectionFile
├── SystemMonitor
├── PluginEventHandlerRegistry (abstract)
│   ├── TSProcessor
│   └── InputSwitcher
└── Report (abstract)
    ├── NullReport (singleton)
    ├── StdErrReport (singleton)
    ├── AsyncReport
    │   └── AbstractAsyncReport (abstract, polling-based)
    └── AbstractSyncReport

AbstractPluginEventHandler (extends NativeObject)
PluginEventContext (data class)
Constants (static constants)
Info (static utility)
```

## Reference

### `Tsduck\Constants`

Static class exposing MPEG Transport Stream constants. No instances.

| Constant                 | Value             | Description                      |
|--------------------------|-------------------|----------------------------------|
| `PKT_SIZE`               | 188               | TS packet size in bytes          |
| `PKT_SIZE_BITS`          | 1504              | TS packet size in bits           |
| `RS_SIZE`                | 16                | Reed-Solomon outer FEC size      |
| `PKT_RS_SIZE`            | 204               | TS packet + RS FEC size          |
| `M2TS_HEADER_SIZE`       | 4                 | M2TS (Blu-ray) header size       |
| `PKT_M2TS_SIZE`          | 192               | M2TS packet size                 |
| `SYSTEM_CLOCK_FREQ`      | 27,000,000        | MPEG-2 System Clock (27 MHz)     |
| `SYSTEM_CLOCK_SUBFACTOR` | 300               | PTS/DTS clock subfactor          |
| `SYSTEM_CLOCK_SUBFREQ`   | 90,000            | PTS/DTS clock frequency (90 kHz) |
| `PCR_BIT_SIZE`           | 42                | PCR size in bits                 |
| `PTS_DTS_BIT_SIZE`       | 33                | PTS/DTS size in bits             |
| `PTS_DTS_SCALE`          | 8,589,934,592     | PTS/DTS wrap scale (2^33)        |
| `PTS_DTS_MASK`           | 8,589,934,591     | PTS/DTS wrap mask                |
| `MAX_PTS_DTS`            | 8,589,934,591     | Maximum PTS/DTS value            |
| `PCR_SCALE`              | 2,576,980,377,600 | PCR wrap scale                   |
| `MAX_PCR`                | 2,576,980,377,599 | Maximum PCR value                |
| `INVALID_PCR`            | -1                | Invalid PCR marker               |
| `INVALID_PTS`            | -1                | Invalid PTS marker               |
| `INVALID_DTS`            | -1                | Invalid DTS marker               |

### `Tsduck\Info`

Static utility class for version information. No instances.

```php
Info::version(): string    // Human-readable version string
Info::intVersion(): int    // Encoded integer: major * 10000000 + minor * 100000 + patch
```

### `Tsduck\DuckContext`

Execution context for MPEG-TS processing. Holds configuration such as character set, CAS ID, private data specifier,
signalization standards, and time reference settings.

```php
$duck = new DuckContext(?Report $report = null);
$duck->close(): void
```

**Standard constants** (bitmask values, combinable with `|`):

| Constant | Value | Description                      |
|----------|-------|----------------------------------|
| `NONE`   | 0x00  | No known standard                |
| `MPEG`   | 0x01  | Defined by MPEG                  |
| `DVB`    | 0x02  | Defined by ETSI/DVB              |
| `SCTE`   | 0x04  | Defined by ANSI/SCTE             |
| `ATSC`   | 0x08  | Defined by ATSC                  |
| `ISDB`   | 0x10  | Defined by ISDB                  |
| `JAPAN`  | 0x20  | Japan-specific (typically +ISDB) |
| `ABNT`   | 0x40  | ABNT/Brazil (typically +ISDB)    |

**Methods:**

```php
$duck->setDefaultCharset(string $charset): void   // Set default charset (empty = DVB default)
$duck->setDefaultCASId(int $casId): void          // Set default CAS ID
$duck->setDefaultPDS(int $pds): void              // Set default private data specifier (0 = none)
$duck->addStandards(int ...$standards): void      // Add standards (OR'ed into bitmask)
$duck->resetStandards(int $mask = NONE): void     // Reset standards to bitmask
$duck->standards(): int                           // Get current standards bitmask
$duck->setTimeReferenceOffset(int $millis): void  // Set UTC offset in milliseconds
$duck->setTimeReference(string $name): void       // Set by name: "JST", "UTC", "UTC+09:00"
```

### `Tsduck\TSProcessor`

Processes an MPEG-TS stream through a chain of plugins. Extends `PluginEventHandlerRegistry` (supports plugin event
handlers).

```php
$tsp = new TSProcessor(?Report $report = null);
$tsp->close(): void
```

**Public properties** (set before calling `start()`):

| Property                  | Type        | Default  | Description                              |
|---------------------------|-------------|----------|------------------------------------------|
| `$ignoreJointTermination` | `bool`      | `false`  | Ignore joint termination options         |
| `$bufferSize`             | `int`       | 16 MB    | Global TS packet buffer size             |
| `$maxFlushedPackets`      | `int`       | 0        | Max packets before flush (0 = default)   |
| `$maxInputPackets`        | `int`       | 0        | Max packets per input operation          |
| `$maxOutputPackets`       | `int`       | 0        | Max packets per output operation         |
| `$initialInputPackets`    | `int`       | 0        | Initial packets before processing starts |
| `$addInputStuffing`       | `list<int>` | `[0, 0]` | `[null_pkt_count, input_pkt_interval]`   |
| `$addStartStuffing`       | `int`       | 0        | Null packets before input                |
| `$addStopStuffing`        | `int`       | 0        | Null packets after input                 |
| `$bitrate`                | `int`       | 0        | Fixed bitrate in bits/s (0 = auto)       |
| `$bitrateAdjustInterval`  | `int`       | 5000     | Bitrate adjust interval in ms            |
| `$receiveTimeout`         | `int`       | 0        | Input timeout in ms (0 = blocking)       |
| `$logPluginIndex`         | `bool`      | `false`  | Log plugin index with name               |

**Methods:**

```php
$tsp->setPlugins(string ...$plugins): self   // Set plugin chain (fluent)
$tsp->start(): void                           // Start processing (throws TSPStartException)
$tsp->abort(): void                           // Request abort (non-blocking)
$tsp->waitForTermination(): void              // Block until processing completes
```

**Plugin specification format:**

```
-I <plugin_name> [args...]   // Input plugin
-P <plugin_name> [args...]   // Processor plugin
-O <plugin_name> [args...]   // Output plugin
```

Example:

```php
$tsp->setPlugins(
    '-I', 'file', 'input.ts',
    '-P', 'filter', '--pid', '100',
    '-P', 'analyze',
    '-O', 'file', 'output.ts'
);
```

### `Tsduck\InputSwitcher`

Manages multiple input plugins with dynamic switching. Extends `PluginEventHandlerRegistry`.

```php
$sw = new InputSwitcher(?Report $report = null);
$sw->close(): void
```

**Public properties:**

| Property            | Type   | Default | Description                           |
|---------------------|--------|---------|---------------------------------------|
| `$fastSwitch`       | `bool` | `false` | Fast switch mode                      |
| `$delayedSwitch`    | `bool` | `false` | Delayed switch mode                   |
| `$terminate`        | `bool` | `false` | Terminate when one input completes    |
| `$reusePort`        | `bool` | `false` | Reuse-port socket option              |
| `$firstInput`       | `int`  | 0       | Index of first input plugin           |
| `$primaryInput`     | `int`  | -1      | Primary input index (-1 = none)       |
| `$cycleCount`       | `int`  | 1       | Number of input cycles (0 = infinite) |
| `$bufferedPackets`  | `int`  | 0       | Input buffer size in packets          |
| `$maxInputPackets`  | `int`  | 0       | Max packets per input read            |
| `$maxOutputPackets` | `int`  | 0       | Max packets per output write          |
| `$sockBuffer`       | `int`  | 0       | Socket buffer size in bytes           |
| `$remoteServerPort` | `int`  | 0       | UDP remote control port (0 = none)    |
| `$receiveTimeout`   | `int`  | 0       | Receive timeout in ms before switch   |
| `$eventUdpPort`     | `int`  | 0       | UDP port for event JSON               |
| `$eventTtl`         | `int`  | 0       | TTL for event UDP                     |

**Methods:**

```php
$sw->setPlugins(string ...$plugins): self       // Set plugin chain (fluent)
$sw->setEventCommand(string ...$commands): self // Shell command on switch event (fluent)
$sw->setEventUdpAddress(string $addr): self    // UDP address for event JSON (fluent)
$sw->setLocalAddress(string $addr): self       // Local interface for event UDP (fluent)
$sw->start(): void                              // Start switcher (throws SwitchStartException)
$sw->setInput(int $index): void                 // Switch to input by index
$sw->nextInput(): void                          // Switch to next input (wraps)
$sw->previousInput(): void                      // Switch to previous input (wraps)
$sw->currentInput(): int                        // Get current input index
$sw->stop(): void                               // Request stop (non-blocking)
$sw->waitForTermination(): void                 // Block until switcher completes
```

Example:

```php
$sw = new InputSwitcher();
$sw->setPlugins(
    '-I', 'file', 'channel1.ts',
    '-I', 'file', 'channel2.ts',
    '-I', 'file', 'channel3.ts',
    '-O', 'ip', '239.1.1.1:5000'
);
$sw->start();

// Switch to next input
$sw->nextInput();

// Or switch by index
$sw->setInput(0);

$sw->waitForTermination();
$sw->close();
```

### `Tsduck\SectionFile`

Loads, saves, and manipulates MPEG-TS sections in binary, XML, and JSON formats.

```php
$sf = new SectionFile(DuckContext $context);
$sf->close(): void
```

**CRC32 validation constants:**

| Constant        | Value | Description                      |
|-----------------|-------|----------------------------------|
| `CRC32_IGNORE`  | 0     | Ignore CRC32 (default)           |
| `CRC32_CHECK`   | 1     | Validate CRC32, fail on mismatch |
| `CRC32_COMPUTE` | 2     | Recompute CRC32 from content     |

**Methods:**

```php
$sf->clear(): void                              // Remove all sections
$sf->binarySize(): int                          // Total binary size in bytes
$sf->sectionsCount(): int                       // Number of sections
$sf->tablesCount(): int                         // Number of complete tables
$sf->setCRCValidation(int $mode): void          // Set CRC32 validation mode

// File I/O
$sf->loadBinary(string $filename): void         // Load from binary file
$sf->saveBinary(string $filename): void         // Save to binary file
$sf->loadXML(string $filename): void            // Load from XML file
$sf->saveXML(string $filename): void            // Save to XML file
$sf->saveJSON(string $filename): void           // Save to JSON file

// In-memory operations
$sf->fromBinary(string $data): void             // Load from binary string
$sf->toBinary(): string                        // Export as binary string
$sf->toXML(): string                           // Export as XML string
$sf->toJSON(): string                          // Export as JSON string

// EIT manipulation
$sf->reorganizeEITs(int $year = 0, int $month = 0, int $day = 0): void
```

Note: `loadBinary()`, `loadXML()`, and `fromBinary()` are additive — loaded sections are appended to existing content.
Call `clear()` first to replace.

### `Tsduck\SystemMonitor`

Monitors system resources (CPU, memory) in a background thread during TS processing.

```php
$monitor = new SystemMonitor(?Report $report = null, string $config = '');
$monitor->close(): void
```

**Methods:**

```php
$monitor->start(): void                // Start background monitoring
$monitor->stop(): void                 // Request stop (non-blocking)
$monitor->waitForTermination(): void   // Block until monitoring stops
```

### Reports

Reports handle logging and diagnostic output. All reports extend `Tsduck\Report\Report`.

#### Severity Levels

| Constant          | Value | Description    |
|-------------------|-------|----------------|
| `Report::Fatal`   | -5    | Fatal error    |
| `Report::Severe`  | -4    | Severe error   |
| `Report::Error`   | -3    | Error          |
| `Report::Warning` | -2    | Warning        |
| `Report::Info`    | -1    | Informational  |
| `Report::Verbose` | 0     | Verbose output |
| `Report::Debug`   | 1     | Debug output   |

#### `NullReport` (singleton)

Silently discards all messages. Default for all constructors that accept an optional `Report`.

```php
$report = NullReport::getInstance();
$report->error('This is silently discarded');
```

#### `StdErrReport` (singleton)

Writes all messages to stderr. Useful for CLI applications and debugging.

```php
$report = StdErrReport::getInstance();
$report->error('This goes to stderr');
```

#### `AsyncReport`

Background-threaded report. Log messages are queued and written asynchronously to avoid blocking the calling thread.

```php
$report = new AsyncReport(
    int $maxSeverity = Report::Debug,   // Maximum severity to report
    int $logMsgCount = 0,               // Max buffered messages (0 = unlimited)
    bool $synchronized = false,         // Synchronous mode
);
$report->terminate();   // Flush pending messages
$report->close();
```

#### `AbstractAsyncReport` (abstract, polling-based)

Polling-based async report for receiving log messages from C++ background threads. This is the thread-safe variant for
PHP — instead of using FFI callbacks (unsafe from non-PHP threads), it polls a message queue.

Subclass and implement `processMessages()`:

```php
use Tsduck\Report\AbstractAsyncReport;
use Tsduck\Report\Report;

class MyReport extends AbstractAsyncReport
{
    public function processMessages(array $messages): void
    {
        foreach ($messages as [$severity, $message]) {
            $label = match ($severity) {
                Report::Error => 'ERROR',
                Report::Warning => 'WARN',
                default => 'INFO',
            };
            echo "[{$label}] {$message}\n";
        }
    }
}

$report = new MyReport(Report::Debug);

// Option 1: Blocking poll loop
$report->run(1000);  // Polls every 1000ms until close()

// Option 2: Manual poll loop
while (!$done) {
    $messages = $report->waitForMessages(1000);
    $report->processMessages($messages);
}

$report->close();
```

**Methods:**

```php
$report->waitForMessages(int $timeoutMs = 1000): array  // Returns [[$severity, $message], ...]
$report->processMessages(array $messages): void          // Override to handle messages
$report->run(int $timeoutMs = 1000): void               // Blocking poll loop
```

**Timeout semantics for `waitForMessages()`:**

- `0` — Non-blocking, returns immediately
- `-1` — Block forever
- `N > 0` — Block up to N milliseconds

#### Common Report Methods

All report subclasses support:

```php
$report->setMaxSeverity(int $level): void    // Filter messages above this level
$report->log(int $severity, string $msg): void
$report->error(string $msg): void
$report->warning(string $msg): void
$report->info(string $msg): void
$report->verbose(string $msg): void
$report->debug(string $msg): void
Report::header(int $severity): string        // Static: get formatted header prefix
```

### Plugin Event Handling

Plugin event handlers allow PHP code to react to events emitted by TS processing plugins.

#### `Tsduck\PluginEventHandler\PluginEventContext`

Data class containing event details. Returned by `waitForEvents()`.

| Property         | Type      | Description                                      |
|------------------|-----------|--------------------------------------------------|
| `$eventId`       | `int`     | Unique event ID (must pass to `completeEvent()`) |
| `$eventCode`     | `int`     | Plugin-defined 32-bit event code                 |
| `$pluginName`    | `string`  | Plugin name                                      |
| `$pluginIndex`   | `int`     | Plugin index in chain (0-based)                  |
| `$pluginCount`   | `int`     | Total plugins in chain                           |
| `$bitrate`       | `int`     | Known bitrate in b/s                             |
| `$pluginPackets` | `int`     | Packets through the plugin                       |
| `$totalPackets`  | `int`     | Total packets at event time                      |
| `$data`          | `?string` | Binary event data, or null                       |
| `$dataSize`      | `int`     | Event data size in bytes                         |
| `$maxDataSize`   | `int`     | Max size for output data (0 = read-only)         |
| `$dataReadOnly`  | `bool`    | Whether event data is read-only                  |

#### `Tsduck\PluginEventHandler\AbstractPluginEventHandler` (abstract)

Polling-based event handler. Subclass and override `handlePluginEvent()`:

```php
use Tsduck\PluginEventHandler\AbstractPluginEventHandler;
use Tsduck\PluginEventHandler\PluginEventContext;

class MyHandler extends AbstractPluginEventHandler
{
    public function handlePluginEvent(PluginEventContext $context): void
    {
        echo "Event {$context->eventCode} from plugin '{$context->pluginName}'\n";
        echo "  Bitrate: {$context->bitrate} b/s\n";
        echo "  Packets: {$context->pluginPackets}\n";

        // You MUST call completeEvent() to unblock the TS pipeline.
        // Failure to do so will deadlock the pipeline.
        $this->completeEvent($context->eventId, true);
    }
}
```

**CRITICAL:** Every event received via `waitForEvents()` or `run()` **must** be completed by calling `completeEvent()`
with the event's `eventId`. The C++ plugin thread blocks on a `std::promise`/`std::future` until `completeEvent()` is
called. The default `handlePluginEvent()` does this automatically, but if you override it, you must ensure completion.

**Methods:**

```php
$handler = new MyHandler(int $maxQueueSize = 1024);
$handler->waitForEvents(int $timeoutMs = 1000): array  // Returns [PluginEventContext, ...]
$handler->completeEvent(int $eventId, bool $success = true, ?string $data = null): void
$handler->handlePluginEvent(PluginEventContext $context): void  // Override this
$handler->run(int $timeoutMs = 1000): void                      // Blocking poll loop
$handler->close(): void
```

**Timeout semantics for `waitForEvents()`:** Same as `AbstractAsyncReport::waitForMessages()`.

**Modifying event data:** If the event is not read-only (`$context->dataReadOnly === false`), you can return modified
data:

```php
public function handlePluginEvent(PluginEventContext $context): void
{
    if (!$context->dataReadOnly && $context->data !== null) {
        // Modify the data and return it
        $modifiedData = $this->transformData($context->data);
        $this->completeEvent($context->eventId, true, $modifiedData);
    } else {
        $this->completeEvent($context->eventId, true);
    }
}
```

#### Registering Event Handlers

Both `TSProcessor` and `InputSwitcher` extend `PluginEventHandlerRegistry`:

```php
use Tsduck\TSProcessor;
use Tsduck\PluginEventHandler\AbstractPluginEventHandler;

$tsp = new TSProcessor();
$handler = new MyEventHandler();

// Register for a specific event code
$tsp->registerEventHandler($handler, 0);

// Register for all input plugin events
$tsp->registerInputEventHandler($handler);

// Register for all output plugin events
$tsp->registerOutputEventHandler($handler);

$tsp->setPlugins('-I', 'file', 'input.ts', '-P', 'svremove', '-O', 'file', 'output.ts');
$tsp->start();

// Run the event handler poll loop (blocks)
$handler->run();

$tsp->waitForTermination();
$tsp->close();
$handler->close();
```

### `Tsduck\PluginEventHandlerRegistry` (abstract)

Base class for `TSProcessor` and `InputSwitcher`. Provides event handler registration.

```php
$registry->registerEventHandler(NativeObject $handler, int $eventCode): void
$registry->registerInputEventHandler(NativeObject $handler): void
$registry->registerOutputEventHandler(NativeObject $handler): void
```

## Exceptions

All exceptions extend `Tsduck\Exception\TsduckException` (which extends `RuntimeException`):

| Exception                  | Thrown by                                                   |
|----------------------------|-------------------------------------------------------------|
| `TsduckException`          | General errors (closed objects, invalid args, FFI failures) |
| `TSPStartException`        | `TSProcessor::start()`                                      |
| `SwitchStartException`     | `InputSwitcher::start()`                                    |
| `VersionMismatchException` | `LibTSDuck` when library version is too old                 |

## Resource Management

All objects backed by native resources extend `NativeObject`. Two patterns are supported:

### Explicit cleanup (recommended)

```php
$duck = new DuckContext();
$sf = new SectionFile($duck);
// ... use objects ...
$sf->close();
$duck->close();
```

### Destructor fallback

Objects are automatically cleaned up by the destructor when they go out of scope or are garbage collected. However,
explicit `close()` is preferred for deterministic resource release.

```php
// Also works — destructor handles cleanup
function process(): void
{
    $duck = new DuckContext();
    $sf = new SectionFile($duck);
    // ... use objects ...
} // $sf and $duck destructors run here
```

### Singleton reports

`NullReport` and `StdErrReport` are process-global singletons. Their `doClose()` is a no-op — the native object is never
freed. Calling `close()` on them is safe but has no effect.

## Thread Safety

PHP's FFI closures are **not safe** to invoke from non-PHP threads (unlike Python's GIL). The PHP bindings use a *
*polling architecture** to handle this:

1. C++ background threads generate events/log messages
2. Messages are queued in thread-safe queues by C++ bridge objects
3. PHP polls the queues from the main thread
4. PHP processes the messages and calls completion methods

This applies to:

- **`AbstractAsyncReport`** — polls for log messages from the C++ AsyncReport background thread
- **`AbstractPluginEventHandler`** — polls for plugin events from TS processing threads

Both provide a `run()` convenience method that blocks in a poll loop, or you can use `waitForMessages()`/
`waitForEvents()` for manual control.

## Library Discovery

The FFI bindings automatically discover the TSDuck shared library using platform-specific search paths:

1. **`TSDUCK` environment variable** — directory containing the library
2. **Platform paths:**
    - **Linux:** `LD_LIBRARY_PATH` directories; `/usr/local/lib` on *BSD
    - **macOS:** `LD_LIBRARY_PATH2`, then `LD_LIBRARY_PATH` directories
    - **Windows:** `TSDUCK` env var, then `Path` directories
3. **System library search** — FFI default resolution (`libtsduck.so` / `libtsduck.dylib` / `tsduck.dll`)

To use a custom library path:

```bash
export TSDUCK=/path/to/tsduck/lib
php script.php
```

## Complete Example: Processing with Event Handling and Async Logging

```php
<?php

use Tsduck\TSProcessor;
use Tsduck\Report\AbstractAsyncReport;
use Tsduck\Report\Report;
use Tsduck\PluginEventHandler\AbstractPluginEventHandler;
use Tsduck\PluginEventHandler\PluginEventContext;

// Custom async report that logs to a file
class FileReport extends AbstractAsyncReport
{
    private $handle;

    public function __construct(string $filename)
    {
        $this->handle = fopen($filename, 'a');
        parent::__construct(Report::Debug);
    }

    public function processMessages(array $messages): void
    {
        foreach ($messages as [$severity, $message]) {
            $ts = date('Y-m-d H:i:s');
            fwrite($this->handle, "[{$ts}] [{$severity}] {$message}\n");
        }
    }

    protected function doClose(): void
    {
        parent::doClose();
        if ($this->handle) {
            fclose($this->handle);
        }
    }
}

// Custom event handler
class EventHandler extends AbstractPluginEventHandler
{
    public function handlePluginEvent(PluginEventContext $context): void
    {
        echo "Plugin '{$context->pluginName}' (index {$context->pluginIndex}): "
           . "event code {$context->eventCode}, "
           . "{$context->pluginPackets} packets processed, "
           . "bitrate {$context->bitrate} b/s\n";

        $this->completeEvent($context->eventId, true);
    }
}

// Set up
$report = new FileReport('/tmp/tsduck.log');
$handler = new EventHandler();

$tsp = new TSProcessor($report);
$tsp->registerEventHandler($handler, 0);  // All event codes
$tsp->setPlugins(
    '-I', 'file', 'input.ts',
    '-P', 'analyze',
    '-O', 'file', 'output.ts'
);
$tsp->start();

// Process events in a separate process or use non-blocking polls
// Here we run the handler loop with a short timeout
// (in production, use pcntl_fork or a proper event loop)
$handler->run(500);

$tsp->waitForTermination();
$tsp->close();
$handler->close();
$report->close();
```
