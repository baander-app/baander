<?php

declare(strict_types=1);

namespace Baander\Tooling\Architecture;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SourcePolicy
{
    private const array PROCESS_FUNCTIONS = ['proc_open', 'exec', 'shell_exec', 'system', 'passthru', 'popen', 'pcntl_exec'];

    /** @return list<array{path:string,line:int,rule:string,message:string}> */
    public static function check(string $root): array
    {
        $diagnostics = [];
        $sources = [];
        foreach (['packages/baander-tooling/src', 'scripts', 'relay/tests', 'relay/tools', '.agents/skills/prose-fix/scripts'] as $directory) {
            if (!is_dir($root . '/' . $directory)) {
                continue;
            }
            $files = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS),
                static fn(SplFileInfo $file): bool => !$file->isLink()
                    && !in_array($file->getFilename(), ['vendor', 'node_modules', '.git'], true),
            );
            foreach (new RecursiveIteratorIterator($files) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        foreach ([
            'tests/Fixtures/Http/dns-orphan-supervisor.php',
            'tests/Fixtures/Http/dns-server.php',
            'tests/Fixtures/Worker/autoscaling-verify.php',
            'tests/Fixtures/Worker/control-capacity-postgres-sample.php',
            'tests/Fixtures/Worker/control-capacity-verify.php',
            'tests/Fixtures/Worker/legacy-runtime.php',
            'tests/Fixtures/Worker/lifecycle-checkpoint.php',
            'tests/Fixtures/Worker/media-control-verify.php',
        ] as $path) {
            if (is_file($root . '/' . $path)) {
                $sources[$path] = (string) file_get_contents($root . '/' . $path);
            }
        }
        $helpers = [];
        foreach ($sources as $path => $source) {
            if ((str_starts_with($path, 'scripts/') && !str_starts_with($path, 'scripts/tests/')) || str_starts_with($path, 'relay/')) {
                foreach (self::globalFunctions(self::tokens($source)) as $name) {
                    $helpers[$name] = $path;
                }
            }
        }
        foreach ($sources as $path => $source) {
            array_push($diagnostics, ...self::analyze($path, $source, $helpers));
        }
        if (isset($sources['packages/baander-tooling/src/Architecture/SourceExceptions.php'])) {
            array_push($diagnostics, ...SourceExceptions::missing(array_keys($sources)));
        }
        usort($diagnostics, static fn(array $a, array $b): int => [$a['path'], $a['line'], $a['rule']] <=> [$b['path'], $b['line'], $b['rule']]);
        return $diagnostics;
    }

    /** @return list<array{path:string,line:int,rule:string,message:string}> */
    public static function violations(string $relativePath, string $source): array
    {
        return self::analyze($relativePath, $source, []);
    }

    /**
     * @param array<string,string> $helpers
     * @return list<array{path:string,line:int,rule:string,message:string}>
     */
    private static function analyze(string $relativePath, string $source, array $helpers): array
    {
        $tokens = self::tokens($source);
        $strings = self::strings($tokens);
        $diagnostics = self::inspect($relativePath, $tokens, $helpers, $strings);
        foreach ($strings as $string) {
            $value = $string['value'];
            if ($relativePath === 'packages/baander-tooling/src/Architecture/SourceExceptions.php') {
                continue;
            }
            if ($relativePath === 'scripts/tests/php/ToolingSourcePolicyTest.php'
                && (str_contains($value, '<?php') || preg_match('/^\s*proc_open\(/', $value))) {
                continue;
            }
            if (str_contains($value, '/var/www/html')) {
                $diagnostics[] = self::diagnostic($relativePath, $string['line'], 'application-root', 'Resolve the application root through ApplicationLayout.', $string['offset']);
            }
            if (preg_match('~(?:martinjuul/baander-app|baander-database|baander-websocket-proxy-test|mcr\.microsoft\.com/playwright|redis/redis-stack-server|axllent/mailpit|nginx):[A-Za-z0-9_.-]+|redis:[0-9][A-Za-z0-9_.-]*-alpine|docker:[0-9][A-Za-z0-9_.-]*-cli~', $value)) {
                $diagnostics[] = self::diagnostic($relativePath, $string['line'], 'default-image', 'Resolve image defaults through ImageCatalog.', $string['offset']);
            }
            if (preg_match('~(?:Baander\\\\Tooling\\\\Development\\\\(?:Runner|Docker)\b|Development/(?:Runner|Docker)\.php)~', $value)) {
                $diagnostics[] = self::diagnostic($relativePath, $string['line'], 'obsolete-helper', 'Remove the obsolete Development process/Docker helper.', $string['offset']);
            }
            if (str_contains($value, "\n") && preg_match('/(?:^|\n)\s*(?:exec\s+)?docker\s+(?:run|exec|build|pull|create|inspect|rm|ps|info|version|compose)\b/', $value)) {
                $diagnostics[] = self::diagnostic($relativePath, $string['line'], 'raw-docker', 'Dispatch generated Docker commands through Client and its resolved target.', $string['offset']);
            }
            if (preg_match('/(?:<\?php|\b(?:proc_open|shell_exec|pcntl_exec|passthru|popen)\s*\()/i', $value)) {
                foreach (self::inspect($relativePath, self::tokens(str_contains($value, '<?php') ? $value : '<?php ' . $value), $helpers) as $violation) {
                    $violation['line'] += $string['line'] - 1;
                    $violation['offset'] += $string['offset'] - (str_contains($value, '<?php') ? 0 : 6);
                    $diagnostics[] = $violation;
                }
            }
        }
        return SourceExceptions::apply($relativePath, $source, $diagnostics);
    }

    /** @return list<array{id:int,text:string,line:int,offset:int}> */
    private static function tokens(string $source): array
    {
        $result = [];
        $offset = 0;
        $line = 1;
        foreach (token_get_all($source) as $token) {
            $id = is_array($token) ? $token[0] : 0;
            $text = is_array($token) ? $token[1] : $token;
            if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $result[] = ['id' => $id, 'text' => $text, 'line' => $line, 'offset' => $offset];
            }
            $offset += strlen($text);
            $line += substr_count($text, "\n");
        }
        return $result;
    }

    /**
     * @param list<array{id:int,text:string,line:int,offset:int}> $tokens
     * @param array<string,string> $helpers
     * @param list<array{value:string,line:int,offset:int,index:int,end:int}>|null $strings
     * @return list<array{path:string,line:int,rule:string,message:string,offset:int}>
     */
    private static function inspect(string $path, array $tokens, array $helpers, ?array $strings = null): array
    {
        $result = [];
        $aliases = [];
        $classAliases = [];
        $composerVariables = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_VARIABLE && ($tokens[$index + 1]['text'] ?? '') === '=') {
                $assignment = '';
                for ($cursor = $index + 2; isset($tokens[$cursor]) && $tokens[$cursor]['text'] !== ';'; $cursor++) {
                    $assignment .= $tokens[$cursor]['text'];
                }
                if (str_contains($assignment, 'vendor/autoload.php')) {
                    $composerVariables[$token['text']] = true;
                }
            }
            if ($token['id'] !== T_USE || ($tokens[$index + 1]['text'] ?? '') === '(') {
                continue;
            }
            $function = ($tokens[$index + 1]['id'] ?? 0) === T_FUNCTION;
            $declaration = '';
            for ($cursor = $index + ($function ? 2 : 1); isset($tokens[$cursor]) && $tokens[$cursor]['text'] !== ';'; $cursor++) {
                $declaration .= $tokens[$cursor]['id'] === T_AS ? ' as ' : $tokens[$cursor]['text'];
            }
            $prefix = '';
            if (str_contains($declaration, '{')) {
                [$prefix, $declaration] = explode('{', $declaration, 2);
                $declaration = rtrim($declaration, '}');
            }
            foreach (explode(',', $declaration) as $import) {
                $parts = explode(' as ', $import);
                $name = ltrim($prefix . $parts[0], '\\');
                $segments = explode('\\', $name);
                $alias = strtolower($parts[1] ?? end($segments));
                if ($function) {
                    $aliases[$alias] = strtolower($name);
                } else {
                    $classAliases[$alias] = $name;
                    if ($prefix !== '' && preg_match('/(?:^|\\\\)Development\\\\(?:Runner|Docker)$/', $name)) {
                        $result[] = self::diagnostic($path, $token['line'], 'obsolete-helper', 'Remove the obsolete Development process/Docker helper.', $token['offset']);
                    }
                }
            }
        }
        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? ['id' => 0, 'text' => ''];
            $next = $tokens[$index + 1]['text'] ?? '';
            $name = strtolower(ltrim($token['text'], '\\'));
            $name = $aliases[$name] ?? $name;
            if (str_starts_with($path, 'packages/baander-tooling/src/') && isset($helpers[$name])
                && in_array($token['id'], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
                && $next === '('
                && !in_array($previous['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                $result[] = self::diagnostic($path, $token['line'], 'composition-helper', 'Package code must not call composition helper ' . $name . ' from ' . $helpers[$name] . '.', $token['offset']);
            }
            if (in_array($token['id'], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)
                && $next === '(' && in_array($name, self::PROCESS_FUNCTIONS, true)
                && !in_array($previous['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                $result[] = self::diagnostic($path, $token['line'], 'process-engine', 'Native process dispatch belongs to the process engine.', $token['offset']);
            }
            if ($previous['id'] === T_NEW && preg_match('/(?:^|\\\\)ProcessRunner$/', $classAliases[strtolower($token['text'])] ?? $token['text'])) {
                $result[] = self::diagnostic($path, $token['line'], 'process-runner', 'Consumers must use the shared command executor.', $token['offset']);
            }
            if (in_array($token['id'], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && preg_match('/(?:^|\\\\)Development\\\\(?:Runner|Docker)$/', $token['text'])) {
                $result[] = self::diagnostic($path, $token['line'], 'obsolete-helper', 'Remove the obsolete Development process/Docker helper.', $token['offset']);
            }
            if (in_array($token['id'], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)
                && !in_array($previous['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                $expression = '';
                for ($cursor = $index + 1; isset($tokens[$cursor]) && $tokens[$cursor]['text'] !== ';'; $cursor++) {
                    $expression .= $tokens[$cursor]['text'];
                }
                if (!preg_match('~(?:vendor/autoload\.php|/bootstrap\.php)~', $expression)
                    && !isset($composerVariables[trim($expression, '()')])) {
                    $result[] = self::diagnostic($path, $token['line'], 'manual-loader', 'Load implementations through Composer.', $token['offset']);
                }
            }
        }
        foreach ($strings ?? self::strings($tokens) as $string) {
            if (!in_array($string['value'], ['docker', '/usr/bin/docker', '/usr/local/bin/docker'], true)) {
                continue;
            }
            $index = $string['index'];
            $previous = $tokens[$index - 1]['text'] ?? '';
            $next = $tokens[$string['end'] + 1]['text'] ?? '';
            $enumeration = ($tokens[$index - 2]['text'] ?? '') === '(' && ($tokens[$index - 3]['id'] ?? 0) === T_FOREACH;
            if (($previous === '[' && $next === ',' && !$enumeration)
                || ($previous === '=' && $next === ';')) {
                $result[] = self::diagnostic($path, $string['line'], 'raw-docker', 'Dispatch Docker through Client and its resolved target.', $string['offset']);
            }
        }
        return $result;
    }

    /**
     * @param list<array{id:int,text:string,line:int,offset:int}> $tokens
     * @return list<string>
     */
    private static function globalFunctions(array $tokens): array
    {
        $names = [];
        $depth = 0;
        $classDepths = [];
        $pendingClass = false;
        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_NAMESPACE && ($tokens[$index + 1]['text'] ?? '') !== '{') {
                return [];
            }
            if (in_array($token['id'], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && ($tokens[$index - 1]['id'] ?? 0) !== T_DOUBLE_COLON) {
                $pendingClass = true;
            }
            if ($token['text'] === '{') {
                $depth++;
                if ($pendingClass) {
                    $classDepths[] = $depth;
                    $pendingClass = false;
                }
            }
            if ($token['text'] === '}') {
                if (end($classDepths) === $depth) {
                    array_pop($classDepths);
                }
                $depth--;
            }
            if ($classDepths === [] && $token['id'] === T_FUNCTION) {
                $next = $index + 1;
                if (in_array($tokens[$next]['text'] ?? '', ['&'], true)) {
                    $next++;
                }
                if (($tokens[$next]['id'] ?? 0) === T_STRING) {
                    $names[] = strtolower($tokens[$next]['text']);
                }
            }
        }
        return $names;
    }

    /**
     * @param list<array{id:int,text:string,line:int,offset:int}> $tokens
     * @return list<array{value:string,line:int,offset:int,index:int,end:int}>
     */
    private static function strings(array $tokens): array
    {
        $result = [];
        foreach ($tokens as $index => $token) {
            if (!in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if ($index > 1 && $tokens[$index - 1]['text'] === '.' && $tokens[$index - 2]['id'] === T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = self::stringValue($token);
            $end = $index;
            while (($tokens[$end + 1]['text'] ?? '') === '.' && ($tokens[$end + 2]['id'] ?? 0) === T_CONSTANT_ENCAPSED_STRING) {
                $value .= self::stringValue($tokens[$end + 2]);
                $end += 2;
            }
            $result[] = ['value' => $value, 'line' => $token['line'], 'offset' => $token['offset'], 'index' => $index, 'end' => $end];
        }
        return $result;
    }

    /** @param array{id:int,text:string,line:int,offset:int} $token */
    private static function stringValue(array $token): string
    {
        if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            return $token['text'];
        }
        $body = substr($token['text'], 1, -1);
        return $token['text'][0] === "'" ? str_replace(["\\\\", "\\'"], ['\\', "'"], $body) : stripcslashes($body);
    }

    /** @return array{path:string,line:int,rule:string,message:string,offset:int} */
    private static function diagnostic(string $path, int $line, string $rule, string $message, int $offset): array
    {
        return ['path' => $path, 'line' => $line, 'rule' => $rule, 'message' => $message, 'offset' => $offset];
    }
}
