# FLAC Metadata Library - Implementation Notes

## Overview

This document captures the key learnings, architectural decisions, and implementation details from building the FLAC metadata library for Baander.

## Architecture Decisions

### Chosen Approach: Clean Architecture (Interface-Driven Design)

**Why this approach:**
- **Extensibility**: Easy to add new formats (OGG, MP4) by implementing interfaces
- **Testability**: Interfaces allow easy mocking and isolation
- **Separation of Concerns**: Reader, parser, and writer responsibilities are clearly separated
- **Backward Compatibility**: Id3Reader wrapper preserves existing MediaMeta functionality

**Key Interfaces:**
```php
MetadataReaderInterface    // Base interface for all format readers
FormatDetectorInterface    // File type detection
FlacReaderInterface       // FLAC-specific operations (extends base)
FlacWriterInterface       // FLAC write operations
```

## Technical Challenges & Solutions

### Challenge 1: Memory Management with Large FLAC Files

**Problem:** Reading entire 42MB audio file into memory caused `Allowed memory size exhausted` errors during tests.

**Solution:** Implement chunked audio data copying (8MB chunks)
```php
$chunkSize = 8 * 1024 * 1024; // 8MB chunks
while (!feof($originalHandle)) {
    $chunk = fread($originalHandle, $chunkSize);
    fwrite($tempHandle, $chunk);
}
```

**Lesson:** Always process large files in chunks when possible, not in memory.

### Challenge 2: Parser Data Storage for Writing

**Problem:** FlacParser initially only stored metadata info (type, length), not raw block data needed for rewriting.

**Solution:** Store raw block data in metadataBlocks array during parsing
```php
$this->metadataBlocks[] = [
    'type' => $this->getBlockTypeName($blockType),
    'typeCode' => $blockType,
    'isLast' => $isLast,
    'length' => $blockLength,
    'data' => $blockData, // Store raw data for writing
];
```

**Lesson:** When building a reader/writer, the reader must preserve all data needed for writing.

### Challenge 3: Endianness in Binary Parsing

**Problem:** FLAC uses mixed endianness:
- Vorbis comments: Little-endian
- METADATA_BLOCK_PICTURE: Big-endian
- Block headers: Big-endian

**Solution:** Careful use of `pack()`/`unpack()` format codes:
```php
// Little-endian (Vorbis)
unpack('V', $data)[1]  // unsigned long (32 bit, little endian)
pack('V', $value)

// Big-endian (Picture, headers)
unpack('N', $data)[1]  // unsigned long (32 bit, big endian)
pack('N', $value)
```

**Lesson:** Always verify endianness specifications for the format you're parsing.

### Challenge 4: Multiple Value Fields

**Problem:** Vorbis comments allow duplicate field names (e.g., multiple ARTIST fields)

**Solution:** Store all fields as arrays, normalize access:
```php
// getArtist() returns string for single, array for multiple
public function getArtist(): array|string|null
{
    $values = $this->getAllValues('ARTIST');
    return empty($values) ? null : (count($values) === 1 ? $values[0] : $values);
}

// getArtists() always returns array
public function getArtists(): array
{
    return $this->getAllValues('ARTIST');
}
```

**Lesson:** Provide both convenient (single value) and complete (array) accessors.

## FLAC Format Specifics

### Metadata Block Structure

```
FLAC File Layout:
+------------------+
| "fLaC" signature  |  (4 bytes)
+------------------+
| Metadata Block 0  |  (always STREAMINFO)
+------------------+
| Metadata Block 1  |  (VORBIS_COMMENT, PICTURE, etc.)
+------------------+
| ...              |
+------------------+
| Metadata Block N  |  (last bit set)
+------------------+
| Audio Frames     |
+------------------+
```

### Block Header Format

```
+----+------+------+
| 1  |   7  |  24  |
+----+------+------+
|Last| Type |Length|
+----+------+------+
```

- **Last bit**: 1 if this is the last metadata block
- **Type**: 7 bits, block type (0=STREAMINFO, 4=VORBIS_COMMENT, 6=PICTURE)
- **Length**: 24 bits big-endian, block data length

### Vorbis Comment Structure

```
+----------------+--------------------------+
| Field          | Format                   |
+----------------+--------------------------+
| Vendor string  | LE length + UTF-8 string |
| Comment count  | LE uint32                |
| Comments       | Repeated:                |
|                |  - LE length             |
|                |  - "FIELD=value" string  |
+----------------+--------------------------+
```

### METADATA_BLOCK_PICTURE Structure

```
+------------------+----------------------+
| Field            | Format               |
+------------------+----------------------+
| Picture type     | BE uint32            |
| MIME type        | BE length + string   |
| Description      | BE length + UTF-8    |
| Width            | BE uint32            |
| Height           | BE uint32            |
| Color depth      | BE uint32            |
| Color count      | BE uint32            |
| Image data       | BE length + binary   |
+------------------+----------------------+
```

## Testing Strategy

### Test File Style

Following project conventions (`GenreHierarchyServiceTest`):
- Base class: `Tests\TestCase`
- Attribute: `#[Test]`
- Method naming: `it_does_something()` (snake_case)
- Descriptive assertions with comments
- `setUp()` method for initialization

### Test Organization

**FlacReaderTest (14 tests):**
- Format detection
- Basic metadata reading
- Multiple artists handling
- Picture extraction
- Stream info
- Vorbis comments
- Metadata blocks

**FlacWriterTest (19 tests):**
- Individual field setters
- Bulk field operations
- Field removal
- Unicode handling
- Audio preservation
- Backup creation
- Method chaining
- Field name normalization

### Test Data

Uses real BABYMETAL FLAC files from `storage/muzak/BABYMETAL - BABYMETAL`:
- Ensures real-world compatibility
- Tests with actual cover art
- Validates against production-quality files

### Test Isolation

Writer tests create temporary file copies:
```php
private function createTempFile(): string
{
    $tempFile = $this->tempDirectory . '/' . uniqid('test_') . '.flac';
    copy($this->sourceFile, $tempFile);
    return $tempFile;
}
```

**Lesson:** Never modify test fixtures; always work with copies.

## Implementation Patterns

### Fluent Interface for Writer

Chainable methods for better UX:
```php
$writer = new FlacWriter($file);
$writer->setTitle('Title')
       ->setArtist('Artist')
       ->setAlbum('Album')
       ->write();
```

### Facade Pattern for Unified Access

```php
// Auto-detects format and delegates
$reader = new MetadataReader($file);

// Format-specific access when needed
if ($reader->getFormat() === 'flac') {
    $flacReader = $reader->getFlacReader();
    $seektable = $flacReader->getSeektable();
}
```

### Value Objects for Pictures

`FlacPicture` as immutable value object:
- Private constructor, use `fromArray()` factory
- No setters, read-only properties
- Type-safe accessors
- Compatible with ID3 APIC via `toApicArray()`

## Best Practices Applied

1. **SOLID Principles**
   - Single Responsibility: Parser, Reader, Writer separate
   - Open/Closed: Easy to add new formats without modifying existing code
   - Liskov Substitution: All readers interchangeable via interface
   - Interface Segregation: Specific interfaces for specific needs
   - Dependency Inversion: Depend on interfaces, not implementations

2. **Defensive Programming**
   - Validate file signatures before parsing
   - Handle missing/invalid data gracefully
   - Create backups before writing
   - Atomic file operations (write to temp, then rename)

3. **Logging**
   - Debug logs for parsing operations
   - Warning logs for recoverable issues
   - Error logs for failures

4. **Error Handling**
   - Custom exceptions for specific error cases
   - Proper exception chaining
   - Cleanup in `finally` blocks

## Performance Considerations

1. **Lazy Loading**: FlacParser only reads file when `parse()` is called
2. **Chunked I/O**: Large files processed in 8MB chunks
3. **Memory Efficiency**: Stream-based copying, not loading entire file
4. **Caching**: FlacReader caches parsed comments and pictures

## Future Enhancements

### Easy to Add

**New Formats:**
1. Implement `MetadataReaderInterface`
2. Add to `MetadataReader::READER_MAP`
3. FormatDetector already supports detection

**OGG Support:**
- OGG uses Vorbis Comments (reuse parsing logic)
- Similar structure to FLAC, different container

**MP4 Support:**
- Different metadata format (iTunes-style atoms)
- Can reuse interface, new implementation

### Potential Improvements

1. **Write Support for Id3Reader**: Currently read-only wrapper
2. **Picture Editing**: Add `removePicturesByType()` to reader
3. **Seektable Editing**: Read/write seektable
4. **CUE Sheet Support**: Parse CUESHEET blocks
5. **Application Blocks**: Handle custom APPLICATION blocks

## Common Pitfalls to Avoid

1. **Forgetting Endianness**: Always verify spec for each field
2. **Memory Issues with Large Files**: Use streams/chunks, not `file_get_contents()`
3. **Off-by-One Errors**: Block type codes are 7-bit, not 8-bit
4. **String Length in Vorbis**: Length is field+value+equals sign
5. **Picture Type Mismatch**: Type codes differ between ID3 and FLAC (though numerically compatible)
6. **Modifying Test Fixtures**: Always work with copies
7. **Ignoring Last Block Bit**: Critical for finding audio data start

## Key Files Reference

| File | Purpose |
|------|---------|
| `FlacParser.php` | Low-level binary parsing |
| `FlacReader.php` | High-level metadata access |
| `FlacWriter.php` | Metadata writing |
| `FlacPicture.php` | Picture value object |
| `FormatDetector.php` | File type detection |
| `MetadataReader.php` | Unified facade |
| `Id3Reader.php` | MediaMeta wrapper |

## Statistics

- **Lines of Code**: ~2,500 (including tests)
- **Test Coverage**: 33 tests, 159 assertions
- **Files Created**: 15+ new files
- **Supported Formats**: FLAC (read/write), ID3v2 (read via MediaMeta)
- **Vorbis Fields**: 20+ standard fields
- **Picture Types**: 20 ID3-compatible types

## References

- [FLAC Format Specification](https://xiph.org/flac/format.html)
- [Vorbis Comment Spec](https://xiph.org/vorbis/doc/v-comment.html)
- [METADATA_BLOCK_PICTURE Spec](https://xiph.org/flac/format.html#metadata_block_picture)

---

*Document Version: 1.0*
*Last Updated: 2025-12-26*
*Implementation Phase: Complete (Read + Write)*
