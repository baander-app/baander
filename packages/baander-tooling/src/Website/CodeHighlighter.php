<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

final class CodeHighlighter
{
    public function highlight(string $source): string
    {
        $prefix = preg_match('/<\?(?:php(?:\s|$)|=)/i', $source) === 1 ? '' : "<?php ";
        $tokens = token_get_all($prefix . $source);
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
            $escaped = htmlspecialchars($literal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $role = $this->role($token, $tokens, $index);
            $html .= $role === null ? $escaped : '<span class="tok-' . $role . '">' . $escaped . '</span>';
        }
        return $html;
    }

    /**
     * @param array{int,string,int}|string $token
     * @param list<array{int,string,int}|string> $tokens
     */
    private function role(array|string $token, array $tokens, int $index): ?string
    {
        if (is_string($token)) {
            return str_contains('+-*/%=!<>|&^~?:.@', $token) ? 'operator' : null;
        }
        $name = token_name($token[0]);
        if (in_array($name, ['T_COMMENT', 'T_DOC_COMMENT'], true)) {
            return 'comment';
        }
        if (in_array($name, ['T_CONSTANT_ENCAPSED_STRING', 'T_ENCAPSED_AND_WHITESPACE', 'T_START_HEREDOC', 'T_END_HEREDOC'], true)) {
            return 'string';
        }
        if (in_array($name, ['T_LNUMBER', 'T_DNUMBER', 'T_NUM_STRING'], true)) {
            return 'number';
        }
        if ($name === 'T_VARIABLE' || $name === 'T_STRING_VARNAME') {
            return 'variable';
        }
        if (in_array($name, ['T_STRING', 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_NAME_RELATIVE'], true)) {
            for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                if (is_array($tokens[$next]) && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $tokens[$next] === '(' ? 'function' : 'type';
            }
            return 'type';
        }
        if (in_array($name, ['T_WHITESPACE', 'T_INLINE_HTML', 'T_OPEN_TAG', 'T_OPEN_TAG_WITH_ECHO', 'T_CLOSE_TAG', 'T_BAD_CHARACTER', 'T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES'], true)) {
            return null;
        }
        if (preg_match('/(?:_EQUAL$|_AND$|_OR$|_XOR$|_SHIFT|_POW|_COALESCE|_ARROW|_OPERATOR$|^T_PIPE$|^T_INC$|^T_DEC$|^T_ELLIPSIS$|^T_DOUBLE_COLON$|^T_SPACESHIP$|^T_IS_)/', $name) === 1) {
            return 'operator';
        }
        return 'keyword';
    }
}
