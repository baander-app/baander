#!/usr/bin/env python3
"""Fact-preservation audit for a prose rewrite.

Usage: python3 audit_rewrite.py OLD.md NEW.md

Reports every mechanical difference that could signal meaning drift:
  - inline-code (backtick) token multiset diff, computed line-by-line so code
    fences cannot produce cross-line false positives
  - number multiset diff (version bumps and date entries are expected; anything
    else needs an explanation)
  - normative-word count deltas (must/never/cannot/can't/always/only/required/
    shall) -- the early-warning signal for normative drift; each delta must
    trace to a specific line and be justified or reverted
  - structural counts: code fences, table rows, headers, section signs
  - structural-line identity: the sequence of blank/fence/header/hr/table lines
    must be identical between old and new

Exit status 0 always; this is a report, not a gate. Read it and account for
every delta before delivering.
"""
import re
import sys
import collections

NORMATIVE = ['must', 'never', 'cannot', "can't", 'always', 'only', 'required', 'shall']


def backtick_tokens(text):
    """Same-line inline-code tokens; fenced blocks never match across lines."""
    out = []
    for line in text.split('\n'):
        out += re.findall(r'`[^`\n]+`', line)
    return collections.Counter(out)


def numbers(text):
    return collections.Counter(re.findall(r'\d+(?:\.\d+)*', text))


def structural_lines(text):
    return [ln for ln in text.split('\n')
            if not ln.strip() or ln.startswith(('#', '```', '---', '|'))]


def count(text, pattern):
    return len(re.findall(pattern, text, re.M))


def main(old_path, new_path):
    old = open(old_path, encoding='utf-8').read()
    new = open(new_path, encoding='utf-8').read()
    problems = 0

    bt_o, bt_n = backtick_tokens(old), backtick_tokens(new)
    lost, gained = bt_o - bt_n, bt_n - bt_o
    print('backtick tokens lost:  ', dict(lost) or '{}')
    print('backtick tokens gained:', dict(gained) or '{}')
    problems += bool(lost or gained)

    nm_o, nm_n = numbers(old), numbers(new)
    print('numbers lost:  ', dict(nm_o - nm_n) or '{}')
    print('numbers gained:', dict(nm_n - nm_o) or '{}')

    for w in NORMATIVE:
        a = len(re.findall(r'\b' + re.escape(w) + r'\b', old, re.I))
        b = len(re.findall(r'\b' + re.escape(w) + r'\b', new, re.I))
        if a != b:
            problems += 1
            print(f'{w}: {a} -> {b}   <<< DELTA -- trace to a line, justify or revert')
        else:
            print(f'{w}: {a} -> {b}')

    for label, pat in [('code fences', r'^```'), ('table rows', r'^\|'),
                       ('headers', r'^#')]:
        a, b = count(old, pat), count(new, pat)
        flag = '' if a == b else '   <<< DELTA'
        problems += a != b
        print(f'{label}: {a} -> {b}{flag}')

    sa, sb = old.count('\u00a7'), new.count('\u00a7')
    flag = '' if sa == sb else '   (review: added refs must state already-true relations)'
    print(f'\u00a7 references: {sa} -> {sb}{flag}')

    if structural_lines(old) == structural_lines(new):
        print('structural lines: identical')
    else:
        problems += 1
        print('structural lines: CHANGED  <<< headers/fences/tables/blanks must not move')

    print()
    print('audit deltas needing explanation:', problems)
    return 0


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    sys.exit(main(sys.argv[1], sys.argv[2]))
