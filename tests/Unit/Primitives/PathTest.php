<?php

namespace Tests\Unit\Primitives;

use App\Primitives\Path;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PathTest extends TestCase
{
    private string $tempDir;
    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/path_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        $this->tempFile = $this->tempDir . '/test_file.txt';
        file_put_contents($this->tempFile, 'test content');
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempFile)) {
            unlink($this->tempFile);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    // ─── Static: join() ───────────────────────────────────────────────────────

    #[Test]
    public function join_combines_segments(): void
    {
        $this->assertSame('foo/bar/baz', Path::join('foo', 'bar', 'baz')->value());
    }

    #[Test]
    public function join_normalizes_duplicate_slashes(): void
    {
        $this->assertSame('foo/bar/baz', Path::join('foo/', '/bar', '/baz')->value());
        $this->assertSame('foo/bar/baz', Path::join('foo//', '//bar//', 'baz')->value());
    }

    #[Test]
    public function join_with_trailing_slashes(): void
    {
        $this->assertSame('foo/bar/', Path::join('foo/', 'bar/')->value());
    }

    #[Test]
    public function join_with_leading_slashes(): void
    {
        $this->assertSame('/foo/bar', Path::join('/foo', 'bar')->value());
        $this->assertSame('/foo/bar', Path::join('/foo', '/bar')->value());
    }

    #[Test]
    public function join_with_single_part(): void
    {
        $this->assertSame('foo', Path::join('foo')->value());
    }

    #[Test]
    public function join_with_empty_parts(): void
    {
        $this->assertSame('foo/bar', Path::join('foo', '', 'bar')->value());
        $this->assertSame('', Path::join('', '', '')->value());
    }

    #[Test]
    public function join_with_no_parts(): void
    {
        $this->assertSame('', Path::make('')->join()->value());
    }

    // ─── Static: normalize() ──────────────────────────────────────────────────

    #[Test]
    public function normalize_resolves_dot_segments(): void
    {
        $this->assertSame('foo/bar', Path::normalize('foo/./bar')->value());
        $this->assertSame('foo/bar', Path::normalize('./foo/bar')->value());
        $this->assertSame('foo/bar', Path::normalize('foo/bar/.')->value());
    }

    #[Test]
    public function normalize_resolves_double_dot_segments(): void
    {
        $this->assertSame('foo/baz', Path::normalize('foo/bar/../baz')->value());
        $this->assertSame('bar/baz', Path::normalize('foo/../bar/baz')->value());
    }

    #[Test]
    public function normalize_preserves_leading_slash(): void
    {
        $this->assertSame('/foo/bar', Path::normalize('/foo/./bar')->value());
        $this->assertSame('/bar', Path::normalize('/foo/../bar')->value());
    }

    #[Test]
    public function normalize_normalizes_backslashes(): void
    {
        $this->assertSame('foo/bar/baz', Path::normalize('foo\\bar\\baz')->value());
    }

    #[Test]
    public function normalize_collapses_multiple_slashes(): void
    {
        $this->assertSame('foo/bar', Path::normalize('foo///bar')->value());
    }

    #[Test]
    public function normalize_empty_string(): void
    {
        $this->assertSame('', Path::normalize('')->value());
    }

    #[Test]
    public function normalize_does_not_resolve_leading_double_dots(): void
    {
        $this->assertSame('../foo', Path::normalize('../foo')->value());
    }

    // ─── Static: basename / dirname / extension / filename ────────────────────

    #[Test]
    public function basename_returns_filename(): void
    {
        $this->assertSame('file.txt', Path::basename('/path/to/file.txt'));
        $this->assertSame('file.txt', Path::basename('file.txt'));
    }

    #[Test]
    public function basename_handles_directory_trailing_slash(): void
    {
        $this->assertSame('dir', Path::basename('/path/to/dir/'));
    }

    #[Test]
    public function dirname_returns_parent_directory(): void
    {
        $this->assertSame('/path/to', Path::dirname('/path/to/file.txt'));
        $this->assertSame('.', Path::dirname('file.txt'));
    }

    #[Test]
    public function extension_returns_lowercase_extension(): void
    {
        $this->assertSame('txt', Path::extension('file.txt'));
        $this->assertSame('php', Path::extension('/path/to/script.PHP'));
        $this->assertSame('gz', Path::extension('archive.tar.gz'));
    }

    #[Test]
    public function extension_returns_empty_for_no_extension(): void
    {
        $this->assertSame('', Path::extension('file'));
        $this->assertSame('hidden', Path::extension('/path/to/.hidden'));
    }

    #[Test]
    public function filename_returns_name_without_extension(): void
    {
        $this->assertSame('file', Path::filename('file.txt'));
        $this->assertSame('archive.tar', Path::filename('archive.tar.gz'));
    }

    // ─── Static: isAbsolute / isRelative ──────────────────────────────────────

    #[Test]
    public function isAbsolute_detects_absolute_paths(): void
    {
        $this->assertTrue(Path::isAbsolute('/foo/bar'));
        $this->assertTrue(Path::isAbsolute('/'));
    }

    #[Test]
    public function isAbsolute_rejects_relative_paths(): void
    {
        $this->assertFalse(Path::isAbsolute('foo/bar'));
        $this->assertFalse(Path::isAbsolute('./foo'));
        $this->assertFalse(Path::isAbsolute('../foo'));
    }

    #[Test]
    public function isRelative_detects_relative_paths(): void
    {
        $this->assertTrue(Path::isRelative('foo/bar'));
        $this->assertTrue(Path::isRelative('./foo'));
        $this->assertTrue(Path::isRelative('../foo'));
    }

    #[Test]
    public function isRelative_rejects_absolute_paths(): void
    {
        $this->assertFalse(Path::isRelative('/foo/bar'));
        $this->assertFalse(Path::isRelative('/'));
    }

    // ─── Static: exists / isFile / isDirectory ────────────────────────────────

    #[Test]
    public function exists_returns_true_for_existing_path(): void
    {
        $this->assertTrue(Path::exists($this->tempDir));
        $this->assertTrue(Path::exists($this->tempFile));
    }

    #[Test]
    public function exists_returns_false_for_nonexistent_path(): void
    {
        $this->assertFalse(Path::exists('/nonexistent/path'));
    }

    #[Test]
    public function isFile_returns_true_for_file(): void
    {
        $this->assertTrue(Path::isFile($this->tempFile));
    }

    #[Test]
    public function isFile_returns_false_for_directory(): void
    {
        $this->assertFalse(Path::isFile($this->tempDir));
    }

    #[Test]
    public function isDirectory_returns_true_for_directory(): void
    {
        $this->assertTrue(Path::isDirectory($this->tempDir));
    }

    #[Test]
    public function isDirectory_returns_false_for_file(): void
    {
        $this->assertFalse(Path::isDirectory($this->tempFile));
    }

    // ─── Static: isReadable / isWritable ──────────────────────────────────────

    #[Test]
    public function isReadable_returns_true_for_readable(): void
    {
        $this->assertTrue(Path::isReadable($this->tempFile));
        $this->assertTrue(Path::isReadable($this->tempDir));
    }

    #[Test]
    public function isWritable_returns_true_for_writable(): void
    {
        $this->assertTrue(Path::isWritable($this->tempFile));
        $this->assertTrue(Path::isWritable($this->tempDir));
    }

    // ─── Static: size ─────────────────────────────────────────────────────────

    #[Test]
    public function size_returns_file_size(): void
    {
        $this->assertSame(12, Path::size($this->tempFile)); // 'test content' = 12 bytes
    }

    #[Test]
    public function size_returns_zero_for_nonexistent(): void
    {
        $this->assertSame(0, Path::size('/nonexistent/file'));
    }

    // ─── Static: ensureTrailingSlash / stripTrailingSlash ─────────────────────

    #[Test]
    public function ensureTrailingSlash_adds_slash(): void
    {
        $this->assertSame('foo/', Path::ensureTrailingSlash('foo')->value());
        $this->assertSame('/foo/', Path::ensureTrailingSlash('/foo')->value());
    }

    #[Test]
    public function ensureTrailingSlash_preserves_existing_slash(): void
    {
        $this->assertSame('foo/', Path::ensureTrailingSlash('foo/')->value());
        $this->assertSame('/', Path::ensureTrailingSlash('/')->value());
    }

    #[Test]
    public function stripTrailingSlash_removes_slash(): void
    {
        $this->assertSame('foo', Path::stripTrailingSlash('foo/')->value());
        $this->assertSame('/foo', Path::stripTrailingSlash('/foo/')->value());
    }

    #[Test]
    public function stripTrailingSlash_preserves_root(): void
    {
        $this->assertSame('/', Path::stripTrailingSlash('/')->value());
    }

    #[Test]
    public function stripTrailingSlash_preserves_no_slash(): void
    {
        $this->assertSame('foo', Path::stripTrailingSlash('foo')->value());
    }

    // ─── Static: glob ─────────────────────────────────────────────────────────

    #[Test]
    public function glob_returns_matching_files(): void
    {
        $result = Path::glob($this->tempDir . '/test_file.*');
        $this->assertCount(1, $result);
        $this->assertSame($this->tempFile, $result[0]);
    }

    #[Test]
    public function glob_returns_empty_for_no_matches(): void
    {
        $result = Path::glob($this->tempDir . '/nonexistent_*');
        $this->assertSame([], $result);
    }

    // ─── Static: resolve ──────────────────────────────────────────────────────

    #[Test]
    public function resolve_returns_absolute_path(): void
    {
        $result = Path::resolve($this->tempDir);
        $this->assertNotNull($result);
        $this->assertTrue(Path::isAbsolute($result));
        $this->assertSame(realpath($this->tempDir), $result);
    }

    #[Test]
    public function resolve_returns_null_for_nonexistent(): void
    {
        $this->assertNull(Path::resolve('/nonexistent/path'));
    }

    // ─── Builder: make / value ────────────────────────────────────────────────

    #[Test]
    public function make_creates_instance(): void
    {
        $path = Path::make('/foo/bar');
        $this->assertSame('/foo/bar', $path->value());
    }

    #[Test]
    public function value_returns_path_string(): void
    {
        $this->assertSame('hello', Path::make('hello')->value());
    }

    // ─── Builder: interfaces ──────────────────────────────────────────────────

    #[Test]
    public function toString_returns_path(): void
    {
        $path = Path::make('/foo/bar');
        $this->assertSame('/foo/bar', (string) $path);
    }

    #[Test]
    public function jsonSerialize_returns_path(): void
    {
        $path = Path::make('/foo/bar');
        $this->assertSame('/foo/bar', $path->jsonSerialize());
    }

    #[Test]
    public function json_encode_works(): void
    {
        $path = Path::make('foo/bar');
        $this->assertSame('"foo\/bar"', json_encode($path));
    }

    // ─── Builder: immutability ────────────────────────────────────────────────

    #[Test]
    public function builder_methods_return_new_instances(): void
    {
        $original = Path::make('/foo/bar');
        $modified = $original->parent();

        $this->assertNotSame($original, $modified);
        $this->assertSame('/foo/bar', $original->value());
        $this->assertSame('/foo', $modified->value());
    }

    #[Test]
    public function original_unchanged_after_chain(): void
    {
        $original = Path::make('/a/b/c');
        $original->parent()->parent();

        $this->assertSame('/a/b/c', $original->value());
    }

    #[Test]
    public function clone_method_creates_independent_copy(): void
    {
        $original = Path::make('/foo');
        $clone = $original->clone();

        $this->assertSame($original->value(), $clone->value());
        $this->assertNotSame($original, $clone);
    }

    // ─── Builder: chaining ────────────────────────────────────────────────────

    #[Test]
    public function builder_chaining_works(): void
    {
        $result = Path::make('/foo/')
            ->stripTrailingSlash()
            ->join('bar', 'baz')
            ->ensureTrailingSlash();

        $this->assertSame('/foo/bar/baz/', $result->value());
    }

    // ─── Builder: join (instance) ─────────────────────────────────────────────

    #[Test]
    public function join_instance_appends_segments(): void
    {
        $this->assertSame('/foo/bar', Path::make('/foo')->join('bar')->value());
        $this->assertSame('/foo/bar/baz', Path::make('/foo')->join('bar', 'baz')->value());
    }

    // ─── Builder: normalize (instance) ────────────────────────────────────────

    #[Test]
    public function normalize_instance_normalizes_path(): void
    {
        $this->assertSame('/foo/bar', Path::make('/foo/./bar')->normalize()->value());
        $this->assertSame('/bar', Path::make('/foo/../bar')->normalize()->value());
    }

    // ─── Builder: ensureTrailingSlash / stripTrailingSlash (instance) ─────────

    #[Test]
    public function ensureTrailingSlash_instance(): void
    {
        $this->assertSame('/foo/', Path::make('/foo')->ensureTrailingSlash()->value());
    }

    #[Test]
    public function stripTrailingSlash_instance(): void
    {
        $this->assertSame('/foo', Path::make('/foo/')->stripTrailingSlash()->value());
    }

    // ─── Builder: parent ──────────────────────────────────────────────────────

    #[Test]
    public function parent_returns_parent_directory(): void
    {
        $this->assertSame('/foo/bar', Path::make('/foo/bar/baz')->parent()->value());
        $this->assertSame('/foo', Path::make('/foo/bar')->parent()->value());
    }

    #[Test]
    public function parent_of_root_stays_root(): void
    {
        $this->assertSame('/', Path::make('/')->parent()->value());
    }

    // ─── Builder: withExtension ───────────────────────────────────────────────

    #[Test]
    public function withExtension_changes_extension(): void
    {
        $this->assertSame('file.md', Path::make('file.txt')->withExtension('md')->value());
    }

    #[Test]
    public function withExtension_strips_leading_dot(): void
    {
        $this->assertSame('file.md', Path::make('file.txt')->withExtension('.md')->value());
    }

    #[Test]
    public function withExtension_preserves_directory(): void
    {
        $this->assertSame('/path/to/file.md', Path::make('/path/to/file.txt')->withExtension('md')->value());
    }

    #[Test]
    public function withExtension_adds_extension_if_missing(): void
    {
        $this->assertSame('file.md', Path::make('file')->withExtension('md')->value());
    }

    #[Test]
    public function withExtension_replaces_multiple_extensions(): void
    {
        $this->assertSame('archive.tar.zip', Path::make('archive.tar.gz')->withExtension('zip')->value());
    }

    // ─── Edge cases ───────────────────────────────────────────────────────────

    #[Test]
    public function empty_path(): void
    {
        $path = Path::make('');
        $this->assertSame('', $path->value());
        $this->assertSame('', (string) $path);
    }

    #[Test]
    public function path_with_special_characters(): void
    {
        $this->assertSame('file name.txt', Path::basename('/path/to/file name.txt'));
        $this->assertSame('/path/to/file name.txt', Path::make('/path/to/file name.txt')->value());
    }

    #[Test]
    public function path_with_spaces(): void
    {
        $result = Path::join('my folder', 'my file.txt');
        $this->assertSame('my folder/my file.txt', $result->value());
    }

    #[Test]
    public function normalize_only_double_dots(): void
    {
        $this->assertSame('..', Path::normalize('..')->value());
        $this->assertSame('../..', Path::normalize('../..')->value());
    }

    #[Test]
    public function normalize_leading_dot_slash(): void
    {
        $this->assertSame('bar', Path::normalize('./bar')->value());
    }

    #[Test]
    public function basename_handles_nested_hidden_file(): void
    {
        $this->assertSame('.env', Path::basename('/app/.env'));
    }

    #[Test]
    public function filename_of_hidden_file(): void
    {
        $this->assertSame('', Path::filename('/app/.env'));
    }

    #[Test]
    public function extension_of_hidden_file(): void
    {
        $this->assertSame('env', Path::extension('/app/.env'));
    }

    #[Test]
    public function join_with_absolute_parts(): void
    {
        // join should just concatenate, not treat absolute parts specially
        $this->assertSame('/foo/bar', Path::join('/foo', '/bar')->value());
    }
}
