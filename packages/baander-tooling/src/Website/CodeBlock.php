<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

/**
 * Wraps highlighted code in the manual's pre/code markup. A named language is shown as a
 * small label through the pre element's data-language attribute, so it never enters the
 * code text that readers select or copy.
 */
final class CodeBlock
{
    private const array LABELS = [
        'bash' => 'Bash', 'sh' => 'Shell', 'shell' => 'Shell', 'zsh' => 'Zsh',
        'env' => '.env', 'dotenv' => '.env', 'json' => 'JSON', 'yaml' => 'YAML', 'yml' => 'YAML',
        'sql' => 'SQL', 'php' => 'PHP', 'ts' => 'TypeScript', 'typescript' => 'TypeScript',
        'tsx' => 'TSX', 'js' => 'JavaScript', 'javascript' => 'JavaScript', 'jsx' => 'JSX',
        'text' => 'Text', 'mermaid' => 'Mermaid',
    ];

    public static function html(string $language, string $highlighted): string
    {
        $label = self::label($language);
        $attribute = $label === null ? '' : ' data-language="' . self::escape($label) . '"';
        return '<pre' . $attribute . '><code class="language-' . self::escape($language) . '">' . $highlighted . '</code></pre>';
    }

    public static function label(string $language): ?string
    {
        $language = strtolower(trim($language));
        if ($language === '') {
            return null;
        }
        return self::LABELS[$language] ?? strtoupper($language);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
