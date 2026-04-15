<?php

namespace Tests\Unit\Primitives;

use App\Primitives\Text;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TextTest extends TestCase
{
    // ─── Static: slug ────────────────────────────────────────────────────────

    #[Test]
    public function slug_converts_string(): void
    {
        $this->assertSame('hello-world', Text::slug('Hello World'));
        $this->assertSame('hello-world', Text::slug('hello world'));
        $this->assertSame('foo-bar-baz', Text::slug('foo bar baz'));
    }

    #[Test]
    public function slug_handles_special_characters(): void
    {
        $this->assertSame('cafe', Text::slug('café'));
        $this->assertSame('naive', Text::slug('naïve'));
    }

    #[Test]
    public function slug_uses_custom_separator(): void
    {
        $this->assertSame('hello_world', Text::slug('hello world', '_'));
        $this->assertSame('hello.world', Text::slug('hello world', '.'));
    }

    #[Test]
    public function slug_returns_empty_for_empty_string(): void
    {
        $this->assertSame('', Text::slug(''));
    }

    #[Test]
    public function slug_handles_multibyte(): void
    {
        $this->assertSame('uber-alles', Text::slug('Über Alles'));
    }

    // ─── Static: random ─────────────────────────────────────────────────────

    #[Test]
    public function random_returns_correct_length(): void
    {
        $this->assertSame(16, strlen(Text::random()));
        $this->assertSame(32, strlen(Text::random(32)));
        $this->assertSame(1, strlen(Text::random(1)));
        $this->assertSame(0, strlen(Text::random(0)));
    }

    #[Test]
    public function random_returns_alphanumeric(): void
    {
        $result = Text::random(100);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $result);
    }

    #[Test]
    public function random_produces_unique_values(): void
    {
        $a = Text::random();
        $b = Text::random();
        $this->assertNotSame($a, $b);
    }

    // ─── Static: contains ───────────────────────────────────────────────────

    #[Test]
    public function contains_checks_single_needle(): void
    {
        $this->assertTrue(Text::contains('Hello World', 'World'));
        $this->assertFalse(Text::contains('Hello World', 'foo'));
    }

    #[Test]
    public function contains_checks_multiple_needles(): void
    {
        $this->assertTrue(Text::contains('Hello World', ['foo', 'World']));
        $this->assertFalse(Text::contains('Hello World', ['foo', 'bar']));
    }

    #[Test]
    public function contains_returns_false_for_empty_needle(): void
    {
        $this->assertFalse(Text::contains('Hello', ''));
    }

    #[Test]
    public function contains_handles_empty_haystack(): void
    {
        $this->assertFalse(Text::contains('', 'foo'));
    }

    #[Test]
    public function contains_handles_multibyte(): void
    {
        $this->assertTrue(Text::contains('café au lait', 'café'));
        $this->assertFalse(Text::contains('café au lait', 'CAFÉ'));
    }

    // ─── Static: startsWith ─────────────────────────────────────────────────

    #[Test]
    public function startsWith_checks_prefix(): void
    {
        $this->assertTrue(Text::startsWith('Hello World', 'Hello'));
        $this->assertFalse(Text::startsWith('Hello World', 'World'));
        $this->assertFalse(Text::startsWith('Hello World', 'hello'));
    }

    #[Test]
    public function startsWith_checks_multiple_needles(): void
    {
        $this->assertTrue(Text::startsWith('Hello World', ['World', 'Hello']));
        $this->assertFalse(Text::startsWith('Hello World', ['foo', 'bar']));
    }

    #[Test]
    public function startsWith_returns_false_for_empty_needle(): void
    {
        $this->assertFalse(Text::startsWith('Hello', ''));
    }

    #[Test]
    public function startsWith_handles_empty_string(): void
    {
        $this->assertFalse(Text::startsWith('', 'foo'));
    }

    // ─── Static: endsWith ───────────────────────────────────────────────────

    #[Test]
    public function endsWith_checks_suffix(): void
    {
        $this->assertTrue(Text::endsWith('Hello World', 'World'));
        $this->assertFalse(Text::endsWith('Hello World', 'Hello'));
        $this->assertFalse(Text::endsWith('Hello World', 'world'));
    }

    #[Test]
    public function endsWith_checks_multiple_needles(): void
    {
        $this->assertTrue(Text::endsWith('Hello World', ['Hello', 'World']));
        $this->assertFalse(Text::endsWith('Hello World', ['foo', 'bar']));
    }

    #[Test]
    public function endsWith_returns_false_for_empty_needle(): void
    {
        $this->assertFalse(Text::endsWith('Hello', ''));
    }

    #[Test]
    public function endsWith_handles_empty_string(): void
    {
        $this->assertFalse(Text::endsWith('', 'foo'));
    }

    // ─── Static: uuid ───────────────────────────────────────────────────────

    #[Test]
    public function uuid_returns_valid_format(): void
    {
        $uuid = Text::uuid();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    #[Test]
    public function uuid_produces_unique_values(): void
    {
        $this->assertNotSame(Text::uuid(), Text::uuid());
    }

    // ─── Static: studly ─────────────────────────────────────────────────────

    #[Test]
    public function studly_converts_to_pascal_case(): void
    {
        $this->assertSame('HelloWorld', Text::studly('hello_world'));
        $this->assertSame('HelloWorld', Text::studly('hello-world'));
        $this->assertSame('HelloWorld', Text::studly('hello world'));
    }

    #[Test]
    public function studly_handles_empty_string(): void
    {
        $this->assertSame('', Text::studly(''));
    }

    #[Test]
    public function studly_handles_single_word(): void
    {
        $this->assertSame('Hello', Text::studly('hello'));
    }

    #[Test]
    public function studly_handles_already_studly(): void
    {
        $this->assertSame('HelloWorld', Text::studly('HelloWorld'));
    }

    // ─── Static: snake ──────────────────────────────────────────────────────

    #[Test]
    public function snake_converts_to_snake_case(): void
    {
        $this->assertSame('hello_world', Text::snake('HelloWorld'));
        $this->assertSame('hello_world', Text::snake('helloWorld'));
    }

    #[Test]
    public function snake_uses_custom_delimiter(): void
    {
        $this->assertSame('hello-world', Text::snake('HelloWorld', '-'));
        $this->assertSame('hello.world', Text::snake('HelloWorld', '.'));
    }

    #[Test]
    public function snake_handles_empty_string(): void
    {
        $this->assertSame('', Text::snake(''));
    }

    #[Test]
    public function snake_converts_spaces(): void
    {
        $this->assertSame('hello_world', Text::snake('hello world'));
        $this->assertSame('hello_world_foo', Text::snake('hello  world  foo'));
    }

    #[Test]
    public function snake_handles_already_snake(): void
    {
        $this->assertSame('hello_world', Text::snake('hello_world'));
    }

    // ─── Static: replaceFirst ───────────────────────────────────────────────

    #[Test]
    public function replaceFirst_replaces_first_occurrence(): void
    {
        $this->assertSame('foo bar baz', Text::replaceFirst('qux', 'bar', 'foo qux baz'));
        $this->assertSame('bar qux baz', Text::replaceFirst('foo', 'bar', 'foo qux baz'));
    }

    #[Test]
    public function replaceFirst_only_replaces_first(): void
    {
        $this->assertSame('bar-foo-foo', Text::replaceFirst('foo', 'bar', 'foo-foo-foo'));
    }

    #[Test]
    public function replaceFirst_returns_subject_when_not_found(): void
    {
        $this->assertSame('hello world', Text::replaceFirst('foo', 'bar', 'hello world'));
    }

    #[Test]
    public function replaceFirst_returns_subject_when_search_empty(): void
    {
        $this->assertSame('hello', Text::replaceFirst('', 'bar', 'hello'));
    }

    #[Test]
    public function replaceFirst_handles_empty_subject(): void
    {
        $this->assertSame('', Text::replaceFirst('foo', 'bar', ''));
    }

    #[Test]
    public function replaceFirst_handles_multibyte(): void
    {
        $this->assertSame('bar-world', Text::replaceFirst('héllo', 'bar', 'héllo-world'));
    }

    // ─── Static: before ─────────────────────────────────────────────────────

    #[Test]
    public function before_returns_string_before_search(): void
    {
        $this->assertSame('hello', Text::before('hello world', ' '));
        $this->assertSame('foo', Text::before('foo@bar.com', '@'));
    }

    #[Test]
    public function before_returns_subject_when_not_found(): void
    {
        $this->assertSame('hello world', Text::before('hello world', 'xyz'));
    }

    #[Test]
    public function before_handles_empty_string(): void
    {
        $this->assertSame('', Text::before('', '@'));
    }

    #[Test]
    public function before_handles_multibyte(): void
    {
        $this->assertSame('café', Text::before('café au lait', ' '));
    }

    // ─── Static: ascii ──────────────────────────────────────────────────────

    #[Test]
    public function ascii_transliterates_latin(): void
    {
        $this->assertSame('Ae', Text::ascii('Äé'));
        $this->assertSame('AE', Text::ascii('ÄÉ'));
        $this->assertSame('cafe', Text::ascii('café'));
        $this->assertSame('naive', Text::ascii('naïve'));
    }

    #[Test]
    public function ascii_handles_german(): void
    {
        $this->assertSame('UEber', Text::ascii('Über', 'de'));
        $this->assertSame('Fussball', Text::ascii('Fußball', 'de'));
        $this->assertSame('aeoeue', Text::ascii('äöü', 'de'));
    }

    #[Test]
    public function ascii_removes_non_ascii(): void
    {
        $this->assertSame('hello', Text::ascii('hello'));
        $this->assertSame('', Text::ascii(''));
    }

    // ─── Static: between ────────────────────────────────────────────────────

    #[Test]
    public function between_extracts_between_markers(): void
    {
        $this->assertSame('bar', Text::between('hello [bar] world', '[', ']'));
        $this->assertSame('world', Text::between('hello (world) test', '(', ')'));
    }

    #[Test]
    public function between_returns_null_for_null(): void
    {
        $this->assertNull(Text::between(null, '[', ']'));
    }

    #[Test]
    public function between_returns_null_for_empty_string(): void
    {
        $this->assertNull(Text::between('', '[', ']'));
    }

    #[Test]
    public function between_returns_null_for_non_string(): void
    {
        $this->assertNull(Text::between(123, '[', ']'));
        $this->assertNull(Text::between([], '[', ']'));
    }

    #[Test]
    public function between_returns_null_when_start_not_found(): void
    {
        $this->assertNull(Text::between('hello world', '[', ']'));
    }

    #[Test]
    public function between_returns_null_when_end_not_found(): void
    {
        $this->assertNull(Text::between('[hello world', '[', ']'));
    }

    #[Test]
    public function between_returns_null_when_start_is_at_position_zero(): void
    {
        // startingWord found at position 0 is treated as invalid (matching StrExt behavior)
        $this->assertNull(Text::between('[hello]', '[', ']'));
    }

    #[Test]
    public function between_returns_null_when_no_content_between_markers(): void
    {
        // a[]b: [ is at position 1, ] is at position 2, size = 0
        $this->assertNull(Text::between('a[]b', '[', ']'));
    }

    // ─── Static: safe ───────────────────────────────────────────────────────

    #[Test]
    public function safe_strips_tags(): void
    {
        $this->assertSame('hello', Text::safe('<p>hello</p>'));
        $this->assertSame('hello world', Text::safe('<b>hello</b> <i>world</i>'));
    }

    #[Test]
    public function safe_removes_null_bytes(): void
    {
        $this->assertSame('helloworld', Text::safe("hello\x00world"));
    }

    #[Test]
    public function safe_returns_null_for_null(): void
    {
        $this->assertNull(Text::safe(null));
    }

    #[Test]
    public function safe_returns_null_for_empty_string(): void
    {
        $this->assertNull(Text::safe(''));
    }

    #[Test]
    public function safe_returns_null_for_non_string(): void
    {
        $this->assertNull(Text::safe(123));
        $this->assertNull(Text::safe([]));
    }

    // ─── Static: convertToUtf8 ──────────────────────────────────────────────

    #[Test]
    public function convertToUtf8_converts_encoding(): void
    {
        // ISO-8859-1 encoded string with special characters
        $isoString = "\xE4\xF6\xFC"; // äöü in ISO-8859-1
        $result = Text::convertToUtf8($isoString);
        $this->assertNotNull($result);
        $this->assertNotFalse(mb_check_encoding($result, 'UTF-8'));
    }

    #[Test]
    public function convertToUtf8_handles_utf8_input(): void
    {
        $this->assertSame('café', Text::convertToUtf8('café'));
    }

    #[Test]
    public function convertToUtf8_returns_null_for_null(): void
    {
        $this->assertNull(Text::convertToUtf8(null));
    }

    #[Test]
    public function convertToUtf8_returns_null_for_empty_string(): void
    {
        $this->assertNull(Text::convertToUtf8(''));
    }

    #[Test]
    public function convertToUtf8_returns_null_for_non_string(): void
    {
        $this->assertNull(Text::convertToUtf8(123));
        $this->assertNull(Text::convertToUtf8([]));
    }

    // ─── Builder: factory and value access ──────────────────────────────────

    #[Test]
    public function make_creates_instance(): void
    {
        $text = Text::make('hello');
        $this->assertSame('hello', $text->value());
    }

    #[Test]
    public function toString_returns_value(): void
    {
        $text = Text::make('hello');
        $this->assertSame('hello', (string) $text);
    }

    #[Test]
    public function jsonSerialize_returns_value(): void
    {
        $text = Text::make('hello');
        $this->assertSame('hello', $text->jsonSerialize());
    }

    #[Test]
    public function json_encode_works(): void
    {
        $text = Text::make('hello');
        $this->assertSame('"hello"', json_encode($text));
    }

    // ─── Builder: immutability ──────────────────────────────────────────────

    #[Test]
    public function builder_methods_return_new_instances(): void
    {
        $original = Text::make('Hello World');
        $modified = $original->lower();

        $this->assertNotSame($original, $modified);
        $this->assertSame('Hello World', $original->value());
        $this->assertSame('hello world', $modified->value());
    }

    #[Test]
    public function builder_chaining_works(): void
    {
        $result = Text::make('  Hello World  ')
            ->trim()
            ->lower()
            ->replace('world', 'everyone');

        $this->assertSame('hello everyone', $result->value());
    }

    // ─── Builder: lower ─────────────────────────────────────────────────────

    #[Test]
    public function lower_converts_to_lowercase(): void
    {
        $this->assertSame('hello', Text::make('HELLO')->lower()->value());
        $this->assertSame('hello world', Text::make('HELLO WORLD')->lower()->value());
    }

    #[Test]
    public function lower_handles_empty_string(): void
    {
        $this->assertSame('', Text::make('')->lower()->value());
    }

    #[Test]
    public function lower_handles_multibyte(): void
    {
        $this->assertSame('café', Text::make('CAFÉ')->lower()->value());
        $this->assertSame('über', Text::make('ÜBER')->lower()->value());
    }

    // ─── Builder: upper ─────────────────────────────────────────────────────

    #[Test]
    public function upper_converts_to_uppercase(): void
    {
        $this->assertSame('HELLO', Text::make('hello')->upper()->value());
    }

    #[Test]
    public function upper_handles_empty_string(): void
    {
        $this->assertSame('', Text::make('')->upper()->value());
    }

    #[Test]
    public function upper_handles_multibyte(): void
    {
        $this->assertSame('CAFÉ', Text::make('café')->upper()->value());
    }

    // ─── Builder: title ─────────────────────────────────────────────────────

    #[Test]
    public function title_converts_to_title_case(): void
    {
        $this->assertSame('Hello World', Text::make('hello world')->title()->value());
        $this->assertSame('Hello World', Text::make('HELLO WORLD')->title()->value());
    }

    #[Test]
    public function title_handles_empty_string(): void
    {
        $this->assertSame('', Text::make('')->title()->value());
    }

    // ─── Builder: trim ──────────────────────────────────────────────────────

    #[Test]
    public function trim_removes_whitespace(): void
    {
        $this->assertSame('hello', Text::make('  hello  ')->trim()->value());
        $this->assertSame('hello world', Text::make("\t hello world \n")->trim()->value());
    }

    #[Test]
    public function trim_removes_custom_characters(): void
    {
        $this->assertSame('hello', Text::make('/hello/')->trim('/')->value());
        $this->assertSame('hello', Text::make('...hello...')->trim('.')->value());
    }

    #[Test]
    public function trim_handles_empty_string(): void
    {
        $this->assertSame('', Text::make('')->trim()->value());
    }

    // ─── Builder: ltrim ─────────────────────────────────────────────────────

    #[Test]
    public function ltrim_removes_leading_whitespace(): void
    {
        $this->assertSame('hello  ', Text::make('  hello  ')->ltrim()->value());
    }

    // ─── Builder: rtrim ─────────────────────────────────────────────────────

    #[Test]
    public function rtrim_removes_trailing_whitespace(): void
    {
        $this->assertSame('  hello', Text::make('  hello  ')->rtrim()->value());
    }

    // ─── Builder: replace ───────────────────────────────────────────────────

    #[Test]
    public function replace_substitutes_all_occurrences(): void
    {
        $this->assertSame('bar-bar-bar', Text::make('foo-foo-foo')->replace('foo', 'bar')->value());
    }

    #[Test]
    public function replace_handles_no_match(): void
    {
        $text = Text::make('hello');
        $this->assertSame('hello', $text->replace('foo', 'bar')->value());
    }

    #[Test]
    public function replace_handles_empty_string(): void
    {
        $this->assertSame('', Text::make('')->replace('foo', 'bar')->value());
    }

    // ─── Builder: replaceLast ───────────────────────────────────────────────

    #[Test]
    public function replaceLast_replaces_last_occurrence(): void
    {
        $this->assertSame('foo-foo-bar', Text::make('foo-foo-foo')->replaceLast('foo', 'bar')->value());
    }

    #[Test]
    public function replaceLast_returns_clone_when_not_found(): void
    {
        $original = Text::make('hello');
        $result = $original->replaceLast('foo', 'bar');
        $this->assertNotSame($original, $result);
        $this->assertSame('hello', $result->value());
    }

    #[Test]
    public function replaceLast_returns_clone_when_search_empty(): void
    {
        $original = Text::make('hello');
        $result = $original->replaceLast('', 'bar');
        $this->assertNotSame($original, $result);
        $this->assertSame('hello', $result->value());
    }

    // ─── Builder: after ─────────────────────────────────────────────────────

    #[Test]
    public function after_returns_string_after_search(): void
    {
        $this->assertSame('bar.com', Text::make('foo@bar.com')->after('@')->value());
    }

    #[Test]
    public function after_returns_original_when_not_found(): void
    {
        $this->assertSame('hello world', Text::make('hello world')->after('xyz')->value());
    }

    #[Test]
    public function after_handles_empty_search(): void
    {
        $this->assertSame('hello', Text::make('hello')->after('')->value());
    }

    // ─── Builder: before ────────────────────────────────────────────────────

    #[Test]
    public function before_builder_returns_string_before_search(): void
    {
        $this->assertSame('foo', Text::make('foo@bar.com')->before('@')->value());
    }

    #[Test]
    public function before_returns_original_when_not_found(): void
    {
        $this->assertSame('hello world', Text::make('hello world')->before('xyz')->value());
    }

    // ─── Builder: substr ────────────────────────────────────────────────────

    #[Test]
    public function substr_extracts_substring(): void
    {
        $this->assertSame('hello', Text::make('hello world')->substr(0, 5)->value());
        $this->assertSame('world', Text::make('hello world')->substr(6)->value());
    }

    #[Test]
    public function substr_handles_negative_start(): void
    {
        $this->assertSame('world', Text::make('hello world')->substr(-5)->value());
    }

    #[Test]
    public function substr_handles_multibyte(): void
    {
        $this->assertSame('café', Text::make('café au lait')->substr(0, 4)->value());
    }

    // ─── Builder: prepend ───────────────────────────────────────────────────

    #[Test]
    public function prepend_adds_prefix(): void
    {
        $this->assertSame('HELLO:hello', Text::make('hello')->prepend('HELLO:')->value());
    }

    #[Test]
    public function prepend_accepts_multiple_values(): void
    {
        $this->assertSame('abchello', Text::make('hello')->prepend('a', 'b', 'c')->value());
    }

    // ─── Builder: append ────────────────────────────────────────────────────

    #[Test]
    public function append_adds_suffix(): void
    {
        $this->assertSame('hello!', Text::make('hello')->append('!')->value());
    }

    #[Test]
    public function append_accepts_multiple_values(): void
    {
        $this->assertSame('helloabc', Text::make('hello')->append('a', 'b', 'c')->value());
    }

    // ─── Builder: camel ─────────────────────────────────────────────────────

    #[Test]
    public function camel_converts_to_camel_case(): void
    {
        $this->assertSame('helloWorld', Text::make('hello_world')->camel()->value());
        $this->assertSame('helloWorld', Text::make('hello-world')->camel()->value());
        $this->assertSame('helloWorld', Text::make('HelloWorld')->camel()->value());
    }

    #[Test]
    public function camel_handles_empty_string(): void
    {
        $this->assertSame('', Text::make('')->camel()->value());
    }

    // ─── Builder: kebab ─────────────────────────────────────────────────────

    #[Test]
    public function kebab_converts_to_kebab_case(): void
    {
        $this->assertSame('hello-world', Text::make('HelloWorld')->kebab()->value());
        $this->assertSame('hello-world', Text::make('hello_world')->kebab()->value());
    }

    #[Test]
    public function kebab_handles_empty_string(): void
    {
        $this->assertSame('', Text::make('')->kebab()->value());
    }

    // ─── Builder: snake (instance) ──────────────────────────────────────────

    #[Test]
    public function snake_instance_converts_to_snake_case(): void
    {
        $this->assertSame('hello_world', Text::make('HelloWorld')->snake()->value());
    }

    #[Test]
    public function snake_instance_uses_custom_delimiter(): void
    {
        $this->assertSame('hello-world', Text::make('HelloWorld')->snake('-')->value());
    }

    // ─── Builder: studly (instance) ─────────────────────────────────────────

    #[Test]
    public function studly_instance_converts_to_studly_case(): void
    {
        $this->assertSame('HelloWorld', Text::make('hello_world')->studly()->value());
    }

    #[Test]
    public function studly_instance_handles_empty_string(): void
    {
        $this->assertSame('', Text::make('')->studly()->value());
    }

    // ─── Builder: complex chaining ──────────────────────────────────────────

    #[Test]
    public function complex_chain_produces_expected_result(): void
    {
        $result = Text::make('  Hello_World_Test  ')
            ->trim()
            ->lower()
            ->snake('-');

        $this->assertSame('hello-world-test', $result->value());
    }

    #[Test]
    public function original_unchanged_after_chain(): void
    {
        $original = Text::make('Hello World');
        $original->upper()->lower()->trim()->append('!');

        $this->assertSame('Hello World', $original->value());
    }

    #[Test]
    public function clone_method_creates_independent_copy(): void
    {
        $original = Text::make('hello');
        $clone = $original->clone();

        $this->assertSame($original->value(), $clone->value());
        $this->assertNotSame($original, $clone);
    }
}
