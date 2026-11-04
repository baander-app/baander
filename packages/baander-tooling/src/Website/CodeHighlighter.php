<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

final class CodeHighlighter
{
    private const array INSIGNIFICANT = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG];

    /** Names after these tokens are class-like: declarations, instantiation, inheritance, imports and attributes. */
    private const array TYPE_CONTEXT = [
        T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS, T_INSTEADOF, T_CLASS,
        T_INTERFACE, T_TRAIT, T_ENUM, T_NAMESPACE, T_USE, T_ATTRIBUTE,
    ];

    public function highlight(string $source): string
    {
        $prefix = preg_match('/<\?(?:php(?:\s|$)|=)/i', $source) === 1 ? '' : "<?php ";
        $tokens = token_get_all($prefix . $source);
        // Neighbours are read across whitespace and comments, so index only the tokens that carry meaning.
        $significant = array_filter($tokens, self::isSignificant(...)) |> array_keys(...);
        $position = array_flip($significant);
        $remaining = strlen($prefix);
        $html = '';
        foreach ($tokens as $index => $token) {
            $literal = is_array($token) ? $token[1] : $token;
            if ($remaining > 0) {
                $removed = min($remaining, strlen($literal));
                $literal = substr($literal, $removed);
                $remaining -= $removed;
            }
            if ($literal === '') {
                continue;
            }
            if (is_array($token) && $token[0] === T_DOC_COMMENT) {
                $html .= $this->docComment($literal);
                continue;
            }
            $at = $position[$index] ?? null;
            $previous = $at !== null && $at > 0 ? $tokens[$significant[$at - 1]] : null;
            $next = $at !== null && isset($significant[$at + 1]) ? $tokens[$significant[$at + 1]] : null;
            $html .= $this->span($this->role($token, $previous, $next), $literal);
        }
        return $html;
    }

    /**
     * @param array{int,string,int}|string $token
     * @param array{int,string,int}|string|null $previous
     * @param array{int,string,int}|string|null $next
     */
    private function role(array|string $token, array|string|null $previous, array|string|null $next): ?string
    {
        if (is_string($token)) {
            if ($token === '"' || $token === '`') {
                return 'string';
            }
            return str_contains('+-*/%=!<>|&^~?:.@', $token) ? 'operator' : null;
        }
        [$id, $text] = $token;
        return match (true) {
            $id === T_COMMENT => 'comment',
            in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_START_HEREDOC, T_END_HEREDOC], true) => 'string',
            in_array($id, [T_LNUMBER, T_DNUMBER, T_NUM_STRING], true) => 'number',
            $id === T_VARIABLE || $id === T_STRING_VARNAME => 'variable',
            in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true) => $this->nameRole($text, $previous, $next),
            $id === T_ARRAY => $next === '(' ? 'keyword' : 'type',
            $id === T_CALLABLE => 'type',
            $id === T_STATIC => $this->is($next, T_DOUBLE_COLON) || in_array($previous, [':', '|', '?'], true) ? 'type' : 'keyword',
            in_array($id, [T_WHITESPACE, T_INLINE_HTML, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_BAD_CHARACTER, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_NS_SEPARATOR], true) => null,
            $id === T_ATTRIBUTE => 'operator',
            preg_match('/(?:_EQUAL$|_AND$|_OR$|_XOR$|_SHIFT|_POW|_COALESCE|_ARROW|_OPERATOR$|^T_PIPE$|^T_INC$|^T_DEC$|^T_ELLIPSIS$|^T_DOUBLE_COLON$|^T_SPACESHIP$|^T_IS_|^T_AMPERSAND_)/', token_name($id)) === 1 => 'operator',
            default => 'keyword',
        };
    }

    /**
     * Classifies an identifier from its neighbours, since PHP's tokenizer reports every name the same way.
     *
     * @param array{int,string,int}|string|null $previous
     * @param array{int,string,int}|string|null $next
     */
    private function nameRole(string $text, array|string|null $previous, array|string|null $next): string
    {
        $call = $next === '(';
        if ($this->is($previous, T_OBJECT_OPERATOR) || $this->is($previous, T_NULLSAFE_OBJECT_OPERATOR)) {
            return $call ? 'function' : 'property';
        }
        if ($this->is($previous, T_DOUBLE_COLON)) {
            return $call ? 'function' : 'constant';
        }
        if (in_array(strtolower($text), ['true', 'false', 'null'], true)) {
            return 'constant';
        }
        if ($this->is($next, T_DOUBLE_COLON)) {
            return 'type';
        }
        foreach (self::TYPE_CONTEXT as $context) {
            if ($this->is($previous, $context)) {
                return 'type';
            }
        }
        if ($this->is($previous, T_CONST)) {
            // A typed class constant names its type first: const string NAME = ...
            return $this->is($next, T_STRING) ? 'type' : 'constant';
        }
        if ($next === '=') {
            // Only constant declarations and declare() directives assign to a bare name.
            return 'constant';
        }
        if ($this->is($previous, T_CASE)) {
            return 'constant';
        }
        if ($next === ':' && in_array($previous, ['(', ','], true)) {
            return 'property';
        }
        if (in_array(strtolower($text), ['get', 'set'], true) && in_array($previous, ['{', ';', '}'], true) && ($next === '{' || $next === ';' || $next === '(' || $this->is($next, T_DOUBLE_ARROW))) {
            return 'keyword';
        }
        if ($call) {
            return 'function';
        }
        if (preg_match('/^[A-Z][A-Z0-9]*_[A-Z0-9_]+$/D', $text) === 1) {
            return 'constant';
        }
        return 'type';
    }

    private function docComment(string $literal): string
    {
        return (preg_split('/(@[a-zA-Z][\w-]*)/', $literal, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [])
            |> (fn(array $parts): array => array_map(
                fn(string $part): string => $this->span(str_starts_with($part, '@') ? 'keyword' : 'comment', $part),
                $parts,
            ))
            |> implode(...);
    }

    /** @param array{int,string,int}|string $token */
    private static function isSignificant(array|string $token): bool
    {
        return !is_array($token) || !in_array($token[0], self::INSIGNIFICANT, true);
    }

    private function span(?string $role, string $literal): string
    {
        $escaped = htmlspecialchars($literal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return $role === null ? $escaped : '<span class="tok-' . $role . '">' . $escaped . '</span>';
    }

    /**
     * @param array{int,string,int}|string|null $token
     */
    private function is(array|string|null $token, int $id): bool
    {
        return is_array($token) && $token[0] === $id;
    }

}
