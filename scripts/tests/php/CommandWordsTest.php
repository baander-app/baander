<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\CommandLine\Words;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandWordsTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function commands(): iterable
    {
        yield 'empty input' => ['', []];
        yield 'whitespace' => [" \t\r\n", []];
        yield 'separated words' => ["php\tconsole\r\nstatus", ['php', 'console', 'status']];
        yield 'empty words' => ['cmd "" \'\' tail', ['cmd', '', '', 'tail']];
        yield 'quoted spaces' => ['cmd "two words" \'three words\'', ['cmd', 'two words', 'three words']];
        yield 'adjacent fragments' => ['a"b c"\'d e\'f', ['ab cd ef']];
        yield 'outside escapes' => ['cmd two\\ words \\"quoted\\" \\\\', ['cmd', 'two words', '"quoted"', '\\']];
        yield 'double quote escapes' => ['"a\\"b\\\\c\\$d\\`e\\q"', ['a"b\\c\\$d\\`e\\q']];
        yield 'single quote escapes stay literal' => ["'a\\b'", ['a\\b']];
        yield 'punctuation stays literal' => ['echo $HOME `id` ; && | > # ~ *', ['echo', '$HOME', '`id`', ';', '&&', '|', '>', '#', '~', '*']];
        yield 'command substitution stays literal' => ["printf '%s' 'quoted value' ';' '$(touch marker)'", ['printf', '%s', 'quoted value', ';', '$(touch marker)']];
        yield 'quoted newline' => ["cmd \"first\nsecond\"", ['cmd', "first\nsecond"]];
        yield 'escaped newline' => ["cmd first\\\nsecond", ['cmd', "first\nsecond"]];
        yield 'double quoted escaped newline' => ["\"first\\\nsecond\"", ["first\\\nsecond"]];
        yield 'unicode and nonbreaking space' => ["héllo\u{00a0}world", ["héllo\u{00a0}world"]];
    }

    /** @param list<string> $expected */
    #[DataProvider('commands')]
    public function testSplitsCommandWithoutEvaluation(string $command, array $expected): void
    {
        self::assertSame($expected, Words::split($command));
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedCommands(): iterable
    {
        yield 'single quote' => ["echo 'open", 'No closing quotation'];
        yield 'double quote' => ['echo "open', 'No closing quotation'];
        yield 'trailing backslash' => ['echo trailing\\', 'No escaped character'];
        yield 'trailing backslash in quotes' => ['echo "trailing\\', 'No escaped character'];
    }

    #[DataProvider('malformedCommands')]
    public function testRejectsMalformedInput(string $command, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        Words::split($command);
    }
}
