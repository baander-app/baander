---
title: refactor: Implement Primitive Helpers framework-independent utilities
type: refactor
status: active
date: 2026-04-15
origin: docs/brainstorms/2026-04-15-primitive-helpers-requirements.md
---

# Refactor: Implement Primitive Helpers

## Overview

Create framework-independent primitive helper classes under `app/Primitives` namespace to replace Laravel's `Illuminate\Support\Str` and `Illuminate\Support\Arr` dependencies while consolidating scattered utilities from `app/Extensions` and `app/Format` into a coherent, well-organized structure.

## Problem Frame

The Bånder codebase currently depends on Laravel's support libraries (`Illuminate\Support\Str`, `Illuminate\Support\Arr`) with 62 total usages across the codebase. These utilities are scattered between `app/Extensions` (generic naming like `StrExt`, `ArrExt`) and `app/Format` (domain-specific utilities). This creates unclear organization, framework coupling, and inconsistent patterns.

**Why this matters:**
- Reduce coupling to Laravel's support libraries for future portability
- Improve code organization with semantic naming (`Text`, `Sequence` instead of `Str`, `Arr`)
- Consolidate scattered utilities into a coherent structure
- Create reusable utilities that could be extracted into standalone packages

**Current pain points:**
- Generic naming (`StrExt`, `ArrExt`) that's not descriptive
- Utilities scattered between `Extensions` and `Format` directories
- 45 Laravel `Str` helper calls using only 11 unique methods
- 17 Laravel `Arr` helper calls using only 6 unique methods
- Unused `GeneratorCollection` class still in codebase
- `EnumExt` trait used on 10 enum classes needs migration

## Requirements Trace

Based on the origin document's comprehensive usage audit and resolved decisions:

- **R1**: Eliminate all imports to `Illuminate\Support\Str` and `Illuminate\Support\Arr` (62 total usages)
- **R2**: Create `app/Primitives` namespace with domain-named utility classes
- **R3**: Implement all currently used Laravel helper methods (17 unique methods total: 11 Str + 6 Arr)
- **R4**: Implement hybrid pattern (static utilities + builder pattern) with magic context switching
- **R5**: Merge `app/Format` classes into Primitives namespace
- **R6**: Remove all `app/Extensions` classes
- **R7**: Migrate 10 enum classes from `EnumExt` trait to new `EnumExtensions` trait
- **R8**: Maintain 100% test coverage for all implemented methods
- **R9**: Ensure immutable builders (return new instances, not `$this`)
- **R10**: Match existing error handling patterns (return `null` for invalid inputs)

## Scope Boundaries

### In Scope
- Create `app/Primitives` namespace with 9 classes: `Text`, `Sequence`, `Number`, `Date`, `Path`, `Bytes`, `Duration`, `LocaleString`, `TextSimilarity`
- Implement 17 core methods currently used from Laravel helpers (85% scope reduction from initial estimates)
- Implement hybrid pattern: static utilities for simple ops, builder for complex chains
- Implement PHP interfaces for magic context switching: `Stringable`, `Arrayable`, `JsonSerializable`, `Countable`, `IteratorAggregate`
- Create `EnumExtensions` trait to replace `EnumExt`
- Migrate all 62 usages via IDE refactoring tools
- Comprehensive unit tests for all methods
- Remove `app/Extensions` directory after migration

### Out of Scope
- Removing Laravel as a framework (only the helper dependencies)
- Creating standalone packages in this iteration
- Implementing every Laravel helper method (only what's actually used)
- Modifying `app/Format` during initial implementation (keep as safety net)
- Performance benchmarking (unless issues discovered during testing)

### Deferred to Separate Tasks
- Performance optimization and benchmarking (can be added iteratively)
- Additional helper methods beyond the 17 currently used
- Extraction into standalone packages

## Context & Research

### Relevant Code and Patterns

**Existing Extension Classes to Replace:**
- `app/Extensions/StrExt.php` - String utilities (`between()`, `safe()`, `convertToUtf8()`)
- `app/Extensions/ArrExt.php` - Array utilities (`dotKeys()`)
- `app/Extensions/EnumExt.php` - Enum trait (`names()`, `values()`, `array()`, `toCamelCase()`)
- `app/Extensions/GeneratorCollection.php` - **UNUSED** (will be removed)

**Existing Format Classes to Migrate:**
- `app/Format/Bytes.php` - Byte formatting with parsing and conversion (625 lines)
- `app/Format/Duration.php` - Time duration with builder pattern (338 lines)
- `app/Format/LocaleString.php` - Locale string delimiters (185 lines)
- `app/Format/TextSimilarity.php` - Text similarity algorithms (634 lines)

**Current Laravel Helper Usage (62 files):**
- `Illuminate\Support\Str`: 45 usages, 11 unique methods
  - `slug()` (15x), `random()` (13x), `contains()` (4x), `endsWith()` (3x), `uuid()` (2x)
  - `studly()` (2x), `snake()` (2x), `startsWith()` (1x), `replaceFirst()` (1x), `before()` (1x), `ascii()` (1x)
- `Illuminate\Support\Arr`: 17 usages, 6 unique methods
  - `first()` (7x), `wrap()` (6x), `last()` (1x), `has()` (1x), `get()` (1x), `where()` (1x)

**Key Patterns to Follow:**
- **Static utilities** (from `Bytes`): Stateless methods with nullable returns
- **Builder pattern** (from `Duration`): Instance-based with fluent API (but make immutable)
- **Hybrid pattern** (from `LocaleString`): Static methods + `make()` factory for builder
- **Type safety**: Use `string`, `int`, `array`, `string|iterable`, `?string`, `?array`
- **Error handling**: Return `null` for invalid inputs (matches `StrExt::between`, `Bytes::parse` patterns)
- **Documentation**: Comprehensive PHPDoc with usage examples

### Institutional Learnings

All critical implementation blockers were resolved in the origin document:

1. **✅ Enum trait strategy**: Keep trait approach - Create `App\Primitives\Traits\EnumExtensions` trait
2. **✅ Builder mutability**: Immutable builders (return new instances, not `$this`)
3. **✅ Method completeness**: Only implement currently used methods (17 total), add more iteratively
4. **✅ Error handling**: Return `null` for invalid inputs (matches existing codebase patterns)
5. **✅ Migration strategy**: Use PHPStorm refactoring tools with Git safety net
6. **✅ Type safety**: Use specific types, nullable returns, union types where appropriate
7. **✅ Scope reduction**: 85% reduction in initial scope (17 methods vs 100+ estimated)

### External References

No external research needed - codebase has strong local patterns and comprehensive requirements analysis already completed.

## Key Technical Decisions

All decisions resolved in origin document (carried forward with rationale):

1. **Hybrid Architecture (Option 3)**: Static utilities + builder pattern
   - **Rationale**: Performance-optimal (static methods avoid instantiation) + flexibility (builder for chaining)
   - **Usage**: Static for simple ops (`Text::slug($string)`), builder for chaining (`Text::make($string)->lower()->replace()`)

2. **Immutable Builders**: All builder methods return NEW instances
   - **Rationale**: Enables safe parallel operations on same base instance
   - **Implementation**: `clone()` method available for explicit copying
   - **Example**: `$base = Text::make('Hello'); $upper = $base->upper(); $lower = $base->lower();` // $base unchanged

3. **Type Safety Standards**: Use specific types, nullable returns, union types
   - **Rationale**: Match existing patterns while improving type safety
   - **Implementation**: `string`, `int`, `array`, `string|iterable`, `?string`, `?array`
   - **Constraint**: Use `mixed` only when truly necessary (e.g., `Arr::get()`)

4. **Error Handling**: Return `null` for invalid inputs
   - **Rationale**: Matches existing codebase patterns (`StrExt::between`, `Bytes::parse`)
   - **Implementation**: Empty strings/arrays return empty values (not null), invalid inputs return null

5. **Interface Implementation**: Magic context switching via PHP interfaces
   - **Text implements**: `Stringable`, `JsonSerializable`
   - **Sequence implements**: `Arrayable`, `JsonSerializable`, `Countable`, `IteratorAggregate`
   - **Rationale**: Enables automatic conversion in string/array contexts

6. **Scope Reduction**: Implement only currently used methods (17 unique)
   - **Rationale**: 85% reduction from initial estimates, add more iteratively
   - **Implementation**: 11 Str methods + 6 Arr methods from actual usage audit

## Open Questions

### Resolved During Planning

All questions were resolved in the origin document before planning began. No blocking questions remain.

### Deferred to Implementation

- Exact method signatures for builder chain methods (will be refined during implementation)
- Final names for any additional helper methods discovered during migration
- Performance characteristics (will be measured during testing, only optimize if needed)

## Output Structure

```
app/Primitives/
├── Text.php              # String utilities (Str from Extensions, functions from StrExt)
├── Sequence.php          # Array utilities (Arr from Extensions, functions from ArrExt)
├── Number.php            # Number utilities (NEW)
├── Date.php              # Date/time utilities (NEW)
├── Path.php              # Filesystem path utilities (NEW)
├── Bytes.php             # From app/Format (byte formatting)
├── Duration.php          # From app/Format (time duration)
├── LocaleString.php      # From app/Format (locale delimiters)
├── TextSimilarity.php    # From app/Format (text similarity algorithms)
└── Traits/
    └── EnumExtensions.php  # Enum trait to replace EnumExt

tests/Unit/Primitives/
├── TextTest.php
├── SequenceTest.php
├── NumberTest.php
├── DateTest.php
├── PathTest.php
├── BytesTest.php
├── DurationTest.php
├── LocaleStringTest.php
└── TextSimilarityTest.php
```

## High-Level Technical Design

> *This illustrates the intended approach and is directional guidance for review, not implementation specification. The implementing agent should treat it as context, not code to reproduce.*

### Hybrid Pattern Architecture

```php
// Static utilities - fast, no instantiation
$slug = Text::slug('Hello World'); // "hello-world"
$first = Sequence::first([1, 2, 3]); // 1

// Builder pattern - fluent chaining for complex operations
$result = Text::make('Hello World')
    ->lower()
    ->replace(' ', '-')
    ->prepend('blog-')
    ->append('-post'); // "blog-hello-world-post"

$filtered = Sequence::make([1, 2, 3, 4, 5])
    ->filter(fn($n) => $n > 2)
    ->map(fn($n) => $n * 2)
    ->sort(); // Sequence([6, 8, 10])
```

### Interface Implementation

```php
// Text class implements Stringable, JsonSerializable
class Text implements Stringable, JsonSerializable
{
    protected string $value;

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }
}

// Sequence class implements Arrayable, JsonSerializable, Countable, IteratorAggregate
class Sequence implements Arrayable, JsonSerializable, Countable, IteratorAggregate
{
    protected array $items;

    public function toArray(): array
    {
        return $this->items;
    }

    public function jsonSerialize(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
```

### Immutable Builder Pattern

```php
// All builder methods return NEW instances
public function upper(): static
{
    $clone = clone $this;
    $clone->value = strtoupper($this->value);
    return $clone;
}

// Enables safe parallel operations
$base = Text::make('Hello World');
$upper = $base->upper();  // New instance "HELLO WORLD"
$lower = $base->lower();  // New instance "hello world"
// $base still contains "Hello World"
```

## Implementation Units

### Phase 1: Foundation (Core Infrastructure)

- [ ] **Unit 1: Create app/Primitives directory structure and traits**

**Goal:** Establish the foundation for all Primitives classes

**Requirements:** R2 (Primitives namespace), R6 (Remove Extensions)

**Dependencies:** None

**Files:**
- Create: `app/Primitives/.gitkeep`
- Create: `app/Primitives/Traits/.gitkeep`
- Create: `app/Primitives/Traits/EnumExtensions.php`
- Test: `tests/Unit/Primitives/EnumExtensionsTest.php`

**Approach:**
- Create directory structure following Laravel conventions
- Implement `EnumExtensions` trait with 4 methods from `EnumExt`: `names()`, `values()`, `array()`, `toCamelCase()`
- Use identical implementation to current `EnumExt` for seamless migration

**Patterns to follow:**
- `app/Extensions/EnumExt.php` - Copy implementation exactly
- Use `static` methods that work on enum's `cases()`

**Test scenarios:**
- Happy path: Trait provides `names()`, `values()`, `array()`, `toCamelCase()` methods
- Edge case: Enum with single case
- Edge case: Enum with backed values
- Integration: Trait methods callable as enum static methods (`AlbumRole::names()`)

**Verification:**
- Trait created with all 4 methods from original `EnumExt`
- Unit tests pass for all enum use cases (single, multiple, backed values)

- [ ] **Unit 2: Create base abstract builder class**

**Goal:** Provide shared builder functionality for all Primitives classes

**Requirements:** R4 (Hybrid pattern), R9 (Immutable builders)

**Dependencies:** Unit 1

**Files:**
- Create: `app/Primitives/Traits/ImmutableBuilder.php`
- Test: No separate tests (tested by implementing classes)

**Approach:**
- Create abstract trait with common builder functionality
- Implement `clone()` method for explicit copying
- Document immutability contract (all methods return new instances)

**Patterns to follow:**
- Builder pattern from `app/Format/Duration.php` (but make immutable)
- Immutability pattern from requirements document

**Test expectation: none -- Tested by implementing classes (Text, Sequence, etc.)**

**Verification:**
- Trait provides `clone()` method
- Documentation clearly states immutability contract

### Phase 2: Core Primitives (Text and Sequence)

- [ ] **Unit 3: Implement Text class with static methods**

**Goal:** Replace Laravel `Str` helper with framework-independent implementation

**Requirements:** R1 (Eliminate Str imports), R3 (11 core methods), R10 (Error handling)

**Dependencies:** Unit 2

**Files:**
- Create: `app/Primitives/Text.php`
- Test: `tests/Unit/Primitives/TextTest.php`

**Approach:**
- Implement 11 core static methods from actual usage audit: `slug()`, `random()`, `contains()`, `endsWith()`, `uuid()`, `studly()`, `snake()`, `startsWith()`, `replaceFirst()`, `before()`, `ascii()`
- Add 3 methods from `StrExt`: `between()`, `safe()`, `convertToUtf8()`
- Add 4 static enum methods: `enumNames()`, `enumValues()`, `enumToArray()`, `toCamelCase()`
- Use `mbstring` functions for multibyte string support
- Return `null` for invalid inputs (matches `StrExt` pattern)
- Use `ramsey/uuid` for UUID generation (already in project)

**Patterns to follow:**
- `app/Extensions/StrExt.php` - Error handling with nullable returns
- `Illuminate\Support\Str` - Method signatures and behavior
- Laravel's `Str::slug()` implementation for URL-safe strings

**Test scenarios:**
- Happy path: All 14 methods work with valid inputs
- Edge case: Empty strings return empty strings (not null)
- Edge case: Null inputs return null
- Edge case: Multibyte characters (UTF-8) handled correctly
- Error path: Invalid encoding returns null
- Integration: `random()` generates cryptographically secure strings
- Integration: `slug()` handles special characters and unicode
- Integration: `uuid()` generates valid UUID v4 format

**Verification:**
- All 14 static methods implemented
- Unit tests cover happy paths, edge cases, error paths
- Multibyte string support confirmed via `mbstring` functions
- UUID generation produces valid format

- [ ] **Unit 4: Implement Text builder with fluent interface**

**Goal:** Add builder pattern for complex string transformations

**Requirements:** R4 (Hybrid pattern), R9 (Immutable builders)

**Dependencies:** Unit 3

**Files:**
- Modify: `app/Primitives/Text.php` (add builder methods)
- Modify: `tests/Unit/Primitives/TextTest.php` (add builder tests)

**Approach:**
- Implement `Stringable` and `JsonSerializable` interfaces
- Add `make(string $string): static` factory method
- Add builder methods: `lower()`, `upper()`, `title()`, `trim()`, `ltrim()`, `rtrim()`, `replace()`, `replaceLast()`, `after()`, `before()`, `substr()`, `prepend()`, `append()`, `camel()`, `kebab()`, `snake()`, `studly()`
- Make all builder methods immutable (return new instances via `clone`)
- Implement `__toString()` to return value
- Implement `jsonSerialize()` to return value

**Patterns to follow:**
- `app/Format/LocaleString.php` - Hybrid static + builder pattern
- `app/Format/Duration.php` - Builder pattern (make immutable)
- Requirements document immutability contract

**Test scenarios:**
- Happy path: Method chaining works fluently
- Happy path: Automatic string conversion in echo/print contexts
- Happy path: JSON serialization returns string value
- Edge case: Chaining multiple methods preserves immutability
- Edge case: `clone()` method creates independent copy
- Integration: Builder works with foreach loops
- Integration: Builder works with json_encode()

**Verification:**
- All builder methods implemented
- `Stringable` interface enables automatic string conversion
- `JsonSerializable` interface enables JSON serialization
- Immutability confirmed via tests (original instance unchanged after chaining)

- [ ] **Unit 5: Implement Sequence class with static methods**

**Goal:** Replace Laravel `Arr` helper with framework-independent implementation

**Requirements:** R1 (Eliminate Arr imports), R3 (6 core methods), R10 (Error handling)

**Dependencies:** Unit 2

**Files:**
- Create: `app/Primitives/Sequence.php`
- Test: `tests/Unit/Primitives/SequenceTest.php`

**Approach:**
- Implement 6 core static methods from actual usage audit: `first()`, `wrap()`, `last()`, `has()`, `get()`, `where()`
- Add `dotKeys()` method from `ArrExt`
- Use `array` functions and SPL iterators where appropriate
- Return `null` for invalid inputs (matches `ArrExt` pattern)
- Support both arrays and `Arrayable` objects

**Patterns to follow:**
- `app/Extensions/ArrExt.php` - Dot notation implementation
- `Illuminate\Support\Arr` - Method signatures and behavior
- Laravel's `Arr::get()` for dot notation support

**Test scenarios:**
- Happy path: All 7 methods work with valid arrays
- Edge case: Empty arrays return empty values (not null)
- Edge case: Null inputs return null
- Edge case: `Arrayable` objects handled correctly
- Error path: Invalid array keys return null
- Integration: `get()` supports dot notation
- Integration: `dotKeys()` flattens nested arrays

**Verification:**
- All 7 static methods implemented
- Unit tests cover happy paths, edge cases, error paths
- `Arrayable` interface support confirmed

- [ ] **Unit 6: Implement Sequence builder with fluent interface**

**Goal:** Add builder pattern for complex array transformations

**Requirements:** R4 (Hybrid pattern), R9 (Immutable builders)

**Dependencies:** Unit 5

**Files:**
- Modify: `app/Primitives/Sequence.php` (add builder methods)
- Modify: `tests/Unit/Primitives/SequenceTest.php` (add builder tests)

**Approach:**
- Implement `Arrayable`, `JsonSerializable`, `Countable`, `IteratorAggregate` interfaces
- Add `make(array $array): static` factory method
- Add builder methods: `map()`, `filter()`, `reduce()`, `pluck()`, `flatten()`, `collapse()`, `unique()`, `sort()`, `shuffle()`, `merge()`, `union()`, `except()`, `only()`
- Make all builder methods immutable (return new instances via `clone`)
- Implement `toArray()` to return items
- Implement `jsonSerialize()` to return items
- Implement `count()` to return item count
- Implement `getIterator()` to enable foreach iteration

**Patterns to follow:**
- Laravel's `Collection` class - Method signatures and behavior
- `app/Format/Duration.php` - Builder pattern (make immutable)
- Requirements document immutability contract

**Test scenarios:**
- Happy path: Method chaining works fluently
- Happy path: Automatic array conversion in foreach loops
- Happy path: JSON serialization returns array
- Edge case: Chaining multiple methods preserves immutability
- Edge case: `clone()` method creates independent copy
- Integration: `Countable` interface enables count() function
- Integration: `IteratorAggregate` enables foreach iteration

**Verification:**
- All builder methods implemented
- All 4 interfaces implemented correctly
- Immutability confirmed via tests (original instance unchanged after chaining)

### Phase 3: Additional Primitives (Number, Date, Path)

- [ ] **Unit 7: Implement Number class**

**Goal:** Add number formatting and manipulation utilities

**Requirements:** R2 (Primitives namespace)

**Dependencies:** Unit 2

**Files:**
- Create: `app/Primitives/Number.php`
- Test: `tests/Unit/Primitives/NumberTest.php`

**Approach:**
- Implement static methods: `format()`, `currency()`, `percentage()`, `bytes()`, `between()`, `clamp()`, `range()`, `isInt()`, `isFloat()`, `round()`, `floor()`, `ceil()`, `abs()`, `min()`, `max()`, `sum()`, `average()`
- Add `make(int|float $number): static` factory method
- Add builder methods mirroring static methods
- Implement immutable builder pattern
- Use `number_format()`, `round()`, `floor()`, `ceil()` functions

**Patterns to follow:**
- `app/Format/Bytes.php` - Number formatting patterns
- Text/Sequence builders - Immutable builder pattern

**Test scenarios:**
- Happy path: Formatting methods produce correct output
- Edge case: Negative numbers handled correctly
- Edge case: Zero values handled correctly
- Edge case: Precision parameter works correctly
- Integration: Builder chaining works fluently

**Verification:**
- All static methods implemented
- Builder methods implemented with immutability
- Unit tests cover happy paths and edge cases

- [ ] **Unit 8: Implement Date class**

**Goal:** Add date/time manipulation utilities

**Requirements:** R2 (Primitives namespace)

**Dependencies:** Unit 2

**Files:**
- Create: `app/Primitives/Date.php`
- Test: `tests/Unit/Primitives/DateTest.php`

**Approach:**
- Implement static factory methods: `now()`, `today()`, `tomorrow()`, `yesterday()`, `parse()`, `create()`, `createFromFormat()`, `createFromTimestamp()`, `createFromTimestampUsec()`
- Implement static comparison methods: `isToday()`, `isYesterday()`, `isTomorrow()`, `isFuture()`, `isPast()`, `isWeekend()`, `isWeekday()`
- Implement static diff methods: `diffInYears()`, `diffInMonths()`, `diffInDays()`, `diffInHours()`, `diffInMinutes()`, `diffInSeconds()`, `humanize()`
- Add `make(string|DateTime $datetime): static` factory method
- Add builder methods: `addYears()`, `subYears()`, `addMonths()`, `subMonths()`, `addDays()`, `subDays()`, `addHours()`, `subHours()`, `addMinutes()`, `subMinutes()`, `addSeconds()`, `subSeconds()`, `startOfDay()`, `endOfDay()`, `startOfWeek()`, `endOfWeek()`, `startOfMonth()`, `endOfMonth()`, `startOfYear()`, `endOfYear()`
- Implement immutable builder pattern
- Use PHP's `DateTime` class internally

**Patterns to follow:**
- Carbon library (Laravel's date library) - Method signatures and behavior
- Text/Sequence builders - Immutable builder pattern

**Test scenarios:**
- Happy path: Factory methods create correct DateTime instances
- Happy path: Comparison methods return correct boolean values
- Happy path: Diff methods return correct intervals
- Edge case: Timezone handling works correctly
- Edge case: Leap years handled correctly
- Integration: Builder chaining works fluently

**Verification:**
- All static methods implemented
- Builder methods implemented with immutability
- Unit tests cover happy paths and edge cases

- [ ] **Unit 9: Implement Path class**

**Goal:** Add filesystem path manipulation utilities

**Requirements:** R2 (Primitives namespace)

**Dependencies:** Unit 2

**Files:**
- Create: `app/Primitives/Path.php`
- Test: `tests/Unit/Primitives/PathTest.php`

**Approach:**
- Implement static methods: `join()`, `normalize()`, `resolve()`, `basename()`, `dirname()`, `extension()`, `filename()`, `isAbsolute()`, `isRelative()`, `exists()`, `isFile()`, `isDirectory()`, `isReadable()`, `isWritable()`, `mkdir()`, `rename()`, `copy()`, `delete()`, `glob()`
- Add `make(string $path): static` factory method
- Add builder methods mirroring static methods
- Implement immutable builder pattern
- Use PHP's `pathinfo()`, `dirname()`, `basename()`, `file_exists()`, `is_file()`, `is_dir()`, `is_readable()`, `is_writable()`, `mkdir()`, `rename()`, `copy()`, `unlink()`, `glob()` functions

**Patterns to follow:**
- Laravel's `Illuminate\Filesystem\Filesystem` - Method signatures and behavior
- Text/Sequence builders - Immutable builder pattern

**Test scenarios:**
- Happy path: Path manipulation methods work correctly
- Edge case: Empty paths handled correctly
- Edge case: Paths with special characters handled correctly
- Integration: Filesystem operations work correctly (use temp files in tests)

**Verification:**
- All static methods implemented
- Builder methods implemented with immutability
- Unit tests cover happy paths and edge cases (with temp file fixtures)

### Phase 4: Migrate Format Classes

- [ ] **Unit 10: Migrate Bytes class to Primitives namespace**

**Goal:** Move Bytes class from Format to Primitives with minimal changes

**Requirements:** R5 (Merge Format into Primitives)

**Dependencies:** Unit 2

**Files:**
- Create: `app/Primitives/Bytes.php`
- Modify: `tests/Unit/Format/BytesTest.php` (update namespace)
- Delete: `app/Format/Bytes.php` (after tests pass)

**Approach:**
- Copy `app/Format/Bytes.php` to `app/Primitives/Bytes.php`
- Update namespace from `App\Format` to `App\Primitives`
- Add PHPDoc examples if needed
- Update test file namespace
- Run tests to ensure no regressions

**Patterns to follow:**
- `app/Format/Bytes.php` - Copy implementation exactly
- Update namespace only, no logic changes

**Test expectation: none -- Tests already exist, just update namespace**

**Verification:**
- Bytes class created in Primitives namespace
- All existing tests pass with updated namespace
- Original Format/Bytes.php deleted after migration confirmed

- [ ] **Unit 11: Migrate Duration class to Primitives namespace**

**Goal:** Move Duration class from Format to Primitives with immutability changes

**Requirements:** R5 (Merge Format into Primitives), R9 (Immutable builders)

**Dependencies:** Unit 2

**Files:**
- Create: `app/Primitives/Duration.php`
- Modify: `tests/Unit/Format/DurationTest.php` (update namespace and add immutability tests)
- Delete: `app/Format/Duration.php` (after tests pass)

**Approach:**
- Copy `app/Format/Duration.php` to `app/Primitives/Duration.php`
- Update namespace from `App\Format` to `App\Primitives`
- **CRITICAL**: Convert mutable builder to immutable pattern
- Update all fluent methods to return new instances instead of `$this`
- Add `clone()` method for explicit copying
- Update test file namespace and add immutability tests
- Run tests to ensure no regressions

**Patterns to follow:**
- `app/Format/Duration.php` - Copy implementation but make immutable
- Requirements document immutability contract

**Test scenarios:**
- Happy path: All existing functionality preserved
- Edge case: Immutability confirmed (original unchanged after chaining)
- Integration: Existing usage patterns still work

**Verification:**
- Duration class created in Primitives namespace
- All fluent methods now return new instances (immutable)
- All existing tests pass with updated namespace
- New immutability tests pass
- Original Format/Duration.php deleted after migration confirmed

- [ ] **Unit 12: Migrate LocaleString class to Primitives namespace**

**Goal:** Move LocaleString class from Format to Primitives with immutability changes

**Requirements:** R5 (Merge Format into Primitives), R9 (Immutable builders)

**Dependencies:** Unit 2

**Files:**
- Create: `app/Primitives/LocaleString.php`
- Modify: `tests/Unit/Format/LocaleStringTest.php` (update namespace and add immutability tests)
- Delete: `app/Format/LocaleString.php` (after tests pass)

**Approach:**
- Copy `app/Format/LocaleString.php` to `app/Primitives/LocaleString.php`
- Update namespace from `App\Format` to `App\Primitives`
- **CRITICAL**: Convert mutable builder to immutable pattern
- Update `set()` and `addDelimiters()` to return new instances
- Update `stripDelimiters()` to return new instances
- Add `clone()` method for explicit copying
- Update test file namespace and add immutability tests
- Run tests to ensure no regressions

**Patterns to follow:**
- `app/Format/LocaleString.php` - Copy implementation but make immutable
- Requirements document immutability contract

**Test scenarios:**
- Happy path: All existing functionality preserved
- Edge case: Immutability confirmed (original unchanged after chaining)
- Integration: Existing usage patterns still work

**Verification:**
- LocaleString class created in Primitives namespace
- All fluent methods now return new instances (immutable)
- All existing tests pass with updated namespace
- New immutability tests pass
- Original Format/LocaleString.php deleted after migration confirmed

- [ ] **Unit 13: Migrate TextSimilarity class to Primitives namespace**

**Goal:** Move TextSimilarity class from Format to Primitives

**Requirements:** R5 (Merge Format into Primitives)

**Dependencies:** Unit 2

**Files:**
- Create: `app/Primitives/TextSimilarity.php`
- Modify: `tests/Unit/Format/TextSimilarityTest.php` (update namespace)
- Delete: `app/Format/TextSimilarity.php` (after tests pass)

**Approach:**
- Copy `app/Format/TextSimilarity.php` to `app/Primitives/TextSimilarity.php`
- Update namespace from `App\Format` to `App\Primitives`
- No logic changes (instance-based class, no builder pattern)
- Update test file namespace
- Run tests to ensure no regressions

**Patterns to follow:**
- `app/Format/TextSimilarity.php` - Copy implementation exactly
- Update namespace only, no logic changes

**Test expectation: none -- Tests already exist, just update namespace**

**Verification:**
- TextSimilarity class created in Primitives namespace
- All existing tests pass with updated namespace
- Original Format/TextSimilarity.php deleted after migration confirmed

### Phase 5: Migration and Cleanup

- [ ] **Unit 14: Migrate enum classes to new EnumExtensions trait**

**Goal:** Replace EnumExt trait usage with new EnumExtensions trait

**Requirements:** R6 (Remove Extensions), R7 (Migrate 10 enum classes)

**Dependencies:** Unit 1

**Files:**
- Modify: 10 enum classes (update trait import)
- Delete: `app/Extensions/EnumExt.php` (after migration confirmed)

**Approach:**
- Use PHPStorm's "Refactor > Rename" to update trait import in 10 enum classes:
  - `App\Models\AlbumRole`
  - `App\Models\AlbumType`
  - `App\Models\LibraryType`
  - `App\Auth\Role`
  - `App\Http\HeaderExt`
  - `App\Modules\Logging\Channel`
  - `App\Modules\Queue\QueueMonitor\MonitorStatus`
  - `App\Modules\Transcoder\Protocol\HttpMethod`
  - `App\Modules\Transcoder\Protocol\MessageType`
  - `App\Modules\Transcoder\Protocol\ErrorCode`
- Change `use App\Extensions\EnumExt;` to `use App\Primitives\Traits\EnumExtensions;`
- Run tests to ensure all enums still work correctly

**Patterns to follow:**
- Requirements document migration strategy (PHPStorm refactoring)
- Simple find/replace on trait import statement

**Test scenarios:**
- Happy path: All 10 enum classes work with new trait
- Integration: Enum static methods still work (`AlbumRole::names()`)

**Verification:**
- All 10 enum classes updated to use new trait
- All enum tests pass
- Original Extensions/EnumExt.php deleted after migration confirmed

- [ ] **Unit 15: Migrate Str helper usages to Text class**

**Goal:** Replace all 45 Illuminate\Support\Str usages with App\Primitives\Text

**Requirements:** R1 (Eliminate Str imports)

**Dependencies:** Unit 3, Unit 4

**Files:**
- Modify: 34 files with Str helper usage
- Test: Run full test suite after migration

**Approach:**
- Use PHPStorm's "Refactor > Rename" to update imports and method calls:
  - `use Illuminate\Support\Str;` → `use App\Primitives\Text;`
  - `Str::` → `Text::`
- Commit before starting migration (safety net)
- Run tests after each batch of files
- Verify all 45 usages migrated correctly

**Patterns to follow:**
- Requirements document migration strategy (PHPStorm refactoring)
- Requirements document usage analysis (45 usages across 34 files)

**Test scenarios:**
- Happy path: All Str usages work with Text class
- Integration: Existing functionality preserved (slugs, random strings, validation)

**Verification:**
- Zero imports to `Illuminate\Support\Str` remain
- All 45 usages migrated to Text class
- Full test suite passes

- [ ] **Unit 16: Migrate Arr helper usages to Sequence class**

**Goal:** Replace all 17 Illuminate\Support\Arr usages with App\Primitives\Sequence

**Requirements:** R1 (Eliminate Arr imports)

**Dependencies:** Unit 5, Unit 6

**Files:**
- Modify: 28 files with Arr helper usage
- Test: Run full test suite after migration

**Approach:**
- Use PHPStorm's "Refactor > Rename" to update imports and method calls:
  - `use Illuminate\Support\Arr;` → `use App\Primitives\Sequence;`
  - `Arr::` → `Sequence::`
- Commit before starting migration (safety net)
- Run tests after each batch of files
- Verify all 17 usages migrated correctly

**Patterns to follow:**
- Requirements document migration strategy (PHPStorm refactoring)
- Requirements document usage analysis (17 usages across 28 files)

**Test scenarios:**
- Happy path: All Arr usages work with Sequence class
- Integration: Existing functionality preserved (array access, filtering, wrapping)

**Verification:**
- Zero imports to `Illuminate\Support\Arr` remain
- All 17 usages migrated to Sequence class
- Full test suite passes

- [ ] **Unit 17: Remove app/Extensions directory**

**Goal:** Clean up old Extensions directory after successful migration

**Requirements:** R6 (Remove Extensions)

**Dependencies:** Unit 14, Unit 15, Unit 16

**Files:**
- Delete: `app/Extensions/` directory
- Test: Run full test suite after deletion

**Approach:**
- Verify all Extensions classes are unused:
  - `StrExt.php` (replaced by Text)
  - `ArrExt.php` (replaced by Sequence)
  - `EnumExt.php` (replaced by EnumExtensions trait)
  - `GeneratorCollection.php` (unused, can be removed)
- Delete entire `app/Extensions/` directory
- Run full test suite to confirm no remaining dependencies

**Patterns to follow:**
- Requirements document migration strategy (keep as safety net during transition)

**Test expectation: none -- Verification is that tests still pass after deletion**

**Verification:**
- `app/Extensions/` directory deleted
- Full test suite passes (no remaining dependencies)
- Git commit shows clean removal

- [ ] **Unit 18: Final verification and cleanup**

**Goal:** Verify all migrations successful and perform final cleanup

**Requirements:** R1-R10 (all requirements met)

**Dependencies:** Unit 17

**Files:**
- Optional: Delete `app/Format/` directory (if confident)
- Update: Documentation (CLAUDE.md, README.md)

**Approach:**
- Run full test suite and verify 100% pass rate
- Verify zero Laravel helper imports remain (grep for `Illuminate\Support\Str` and `Illuminate\Support\Arr`)
- Verify all Extensions classes removed
- Optionally delete `app/Format/` directory (keep as safety net if uncertain)
- Update documentation with new Primitives namespace
- Run manual smoke tests on critical paths (OAuth, scanning, metadata)

**Patterns to follow:**
- Requirements document success criteria
- Requirements document definition of done

**Test expectation: none -- Final verification of all work**

**Verification:**
- Zero imports to Laravel helper classes
- All app/Extensions classes removed
- All utilities consolidated under app/Primitives
- All tests pass (100% pass rate)
- Documentation updated with new patterns
- Manual smoke tests pass (OAuth, scanning, metadata)

## System-Wide Impact

- **Interaction graph:** This refactor touches 62 files across the codebase. All interactions are read-only utility calls, so no observer or callback patterns are affected.

- **Error propagation:** Error handling is preserved from existing patterns (return `null` for invalid inputs). No new error propagation paths are introduced.

- **State lifecycle risks:** No state lifecycle risks - all Primitives classes are either static utilities (stateless) or immutable builders (state never modified after creation).

- **API surface parity:** No API surface changes - this is an internal refactor only. Public APIs remain unchanged.

- **Integration coverage:** Unit tests cover individual method behavior. Integration scenarios tested:
  - Enum trait methods work with enum static methods
  - Builder chaining preserves immutability
  - Interface implementations enable automatic type conversion
  - Laravel helper replacements maintain identical behavior

- **Unchanged invariants:**
  - Public API contracts remain unchanged
  - Database queries and models unaffected
  - HTTP controllers and responses unaffected
  - Queue jobs and workers unaffected
  - Frontend TypeScript code unaffected
  - All existing tests continue to pass with new implementation

## Risks & Dependencies

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **Migration breaks existing code** | Low | High | Use PHPStorm refactoring tools with automatic reference tracking; Commit before each major step; Keep app/Format as safety net; Git rollback available |
| **Immutable builder pattern unfamiliar to team** | Medium | Low | Document pattern clearly in code comments; Requirements document has extensive examples; Pattern matches functional programming principles |
| **Performance regression in hot paths** | Low | Medium | Static methods have minimal overhead; Profile before/after if performance issues detected; Only optimize if actual problems found |
| **Test coverage gaps for edge cases** | Medium | Medium | Comprehensive unit tests for all 17 methods; Test null handling, empty inputs, multibyte characters; Test immutability explicitly |
| **Enum trait migration breaks existing usage** | Low | High | Trait implementation identical to EnumExt; Simple find/replace on import statement; Test all 10 enum classes after migration |
| **Missed Laravel helper usage** | Low | Medium | Comprehensive usage audit completed (62 files); Grep for remaining imports after migration; Full test suite catches any misses |
| **Type safety issues with union types** | Low | Low | Follow existing patterns from codebase; Use specific types when possible; Nullable returns for edge cases |

## Documentation / Operational Notes

### Documentation Updates

- **CLAUDE.md**: Update "Code Organization" section to reflect `app/Primitives` namespace instead of `app/Extensions` and `app/Format`
- **README.md**: No changes needed (internal refactor)
- **Developer documentation**: Add section on "Using Primitive Helpers" with examples of static utilities vs builder pattern

### Migration Notes for Developers

- **Why this change**: Reduce framework coupling, improve code organization
- **How to use**: Static utilities for simple ops, builder for chaining
- **Breaking changes**: None for external consumers (internal refactor only)
- **Performance**: No performance regression expected (static methods are fast)

### Operational Impact

- **No deployment changes**: This is pure code refactor, no infrastructure changes
- **No configuration changes**: No config files modified
- **No database changes**: No migrations or schema changes
- **Testing**: Full test suite must pass before deployment

## Sources & References

- **Origin document:** [docs/brainstorms/2026-04-15-primitive-helpers-requirements.md](../brainstorms/2026-04-15-primitive-helpers-requirements.md)
- **Architecture comparison:** [docs/brainstorms/primitive-approaches-comparison.md](../brainstorms/primitive-approaches-comparison.md)
- **Existing implementations:**
  - [app/Extensions/StrExt.php](../../app/Extensions/StrExt.php)
  - [app/Extensions/ArrExt.php](../../app/Extensions/ArrExt.php)
  - [app/Extensions/EnumExt.php](../../app/Extensions/EnumExt.php)
  - [app/Format/Bytes.php](../../app/Format/Bytes.php)
  - [app/Format/Duration.php](../../app/Format/Duration.php)
  - [app/Format/LocaleString.php](../../app/Format/LocaleString.php)
  - [app/Format/TextSimilarity.php](../../app/Format/TextSimilarity.php)
- **Laravel helper usage:** Analysis in origin document (45 Str calls + 17 Arr calls)
- **Testing patterns:** [tests/Unit/](../../tests/Unit/) directory structure

## Success Metrics

From the origin document's Definition of Done:

- [ ] Zero imports to `Illuminate\Support\Str` or `Illuminate\Support\Arr` (45 Str calls + 17 Arr calls migrated)
- [ ] All `App\Extensions` classes removed (4 classes: StrExt, ArrExt, EnumExt, GeneratorCollection)
- [ ] All utilities consolidated under `app/Primitives` (Text, Sequence, Number, Date, Path + 4 Format classes)
- [ ] 10 enum classes migrated to new `EnumExtensions` trait
- [ ] 100% test coverage for all implemented methods (17 core methods + builder methods)
- [ ] All existing tests pass after migration
- [ ] No performance regression in critical paths (verified via smoke tests)
- [ ] Documentation updated with new usage patterns
- [ ] Migration completed using IDE refactoring tools with Git safety net
