import { describe, expect, it } from 'vitest';
import { declaration, renderWithCss, rulesForElement, type CssRule } from '@/shared/theme/__tests__/css-text';
import { Badge } from '../badge';

function badgeRule(): CssRule {
  const { container, cssText } = renderWithCss(<Badge>Lossless</Badge>);
  const rules = rulesForElement(container.firstElementChild!, cssText);

  expect(rules).toHaveLength(1);

  return rules[0];
}

describe('Badge', () => {
  it('grows with its text through a minimum height instead of a fixed one', () => {
    const rule = badgeRule();

    expect(declaration(rule, 'min-height')).toBe('1.25rem');
    expect(declaration(rule, 'height')).toBeUndefined();
  });

  it('sets its text in the caption role', () => {
    const rule = badgeRule();

    expect(declaration(rule, 'font-size')).toBe('calc(var(--text-caption-size) + var(--script-size-boost, 0px))');
    expect(declaration(rule, 'line-height')).toBe('max(var(--text-caption-line-height), var(--script-line-height, 0))');
  });
});
