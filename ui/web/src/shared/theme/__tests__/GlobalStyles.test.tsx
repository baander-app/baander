import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { cleanup } from '@testing-library/react';
import { GlobalStyles } from '../GlobalStyles';
import { resolveTheme } from '../resolve-theme';
import { cssRules, declaration, renderWithCss, type CssRule } from './css-text';

const SCRIPT_PROPERTIES = ['--tracking-scale', '--script-line-height', '--script-size-boost'];

let rules: CssRule[];

function ruleFor(selector: string): CssRule {
  const rule = rules.find(candidate => candidate.selector === selector);

  expect(rule, `no rule for ${selector}`).toBeDefined();

  return rule!;
}

/** The emitted CSS is minified, which drops the space after each comma in a font stack. */
function withoutSpaceAfterCommas(stack: string): string {
  return stack.replace(/,\s+/g, ',');
}

function scriptRuleIndex(token: string): number {
  return rules.findIndex(rule => rule.selector === `[data-script~="${token}"]`
    && declaration(rule, '--script-line-height') !== undefined);
}

describe('GlobalStyles', () => {
  beforeAll(() => {
    rules = cssRules(renderWithCss(<GlobalStyles />).cssText);
  });

  afterAll(() => {
    cleanup();
  });

  it('sets the body in the UI stack and the body role without global tracking', () => {
    const body = ruleFor('body');

    expect(declaration(body, 'font-family')).toBe('var(--font-ui)');
    expect(declaration(body, 'font-size')).toBe('calc(var(--text-body-size) + var(--script-size-boost, 0px))');
    expect(declaration(body, 'letter-spacing')).toBe('calc(var(--text-body-tracking) * var(--tracking-scale, 1))');
  });

  it('zeroes the tracking scale for the no-tracking script token', () => {
    expect(declaration(ruleFor('[data-script~="no-tracking"]'), '--tracking-scale')).toBe('0');
  });

  it('sets no script property from a :lang() rule', () => {
    const langRules = rules.filter(rule => rule.selector.includes(':lang('));

    expect(langRules.length).toBeGreaterThan(0);

    for (const rule of langRules) {
      for (const property of SCRIPT_PROPERTIES) {
        expect(declaration(rule, property), `${rule.selector} sets ${property}`).toBeUndefined();
      }
    }
  });

  it('declares the script line-height minimums in ascending order', () => {
    const cjk = scriptRuleIndex('cjk');
    const thai = scriptRuleIndex('thai');
    const burmese = scriptRuleIndex('burmese');

    expect(declaration(rules[cjk], '--script-line-height')).toBe('1.6');
    expect(declaration(rules[thai], '--script-line-height')).toBe('1.8');
    expect(declaration(rules[burmese], '--script-line-height')).toBe('2');
    expect(cjk).toBeLessThan(thai);
    expect(thai).toBeLessThan(burmese);
  });

  it('enlarges wholly Burmese text by 2px', () => {
    expect(declaration(ruleFor('[data-script~="burmese-only"]'), '--script-size-boost')).toBe('2px');
  });

  it('lets CJK text break anywhere and Japanese text break strictly', () => {
    expect(declaration(ruleFor('[data-script~="cjk"]'), 'overflow-wrap')).toBe('anywhere');
    expect(declaration(ruleFor('[lang]:lang(ja)'), 'line-break')).toBe('strict');
  });

  it('reorders the stacks for Japanese, Korean and Traditional Chinese', () => {
    const japanese = ruleFor('[lang]:lang(ja)');
    const korean = ruleFor('[lang]:lang(ko)');
    const traditional = rules.find(rule => rule.selector.includes(':lang(zh-Hant)'));

    expect(declaration(japanese, '--font-ui')).toBe('var(--font-ui-ja)');
    expect(declaration(japanese, '--font-mono')).toBe('var(--font-mono-ja)');
    expect(declaration(korean, '--font-ui')).toBe('var(--font-ui-ko)');
    expect(declaration(korean, '--font-mono')).toBe('var(--font-mono-ko)');
    expect(traditional).toBeDefined();
    expect(traditional!.selector).toContain('[lang]:lang(zh-TW)');
    expect(declaration(traditional!, '--font-ui')).toBe('var(--font-ui-hant)');
    expect(declaration(traditional!, '--font-mono')).toBe('var(--font-mono-hant)');
  });

  it('resets the stacks and script properties on any other element with a language', () => {
    const { typography } = resolveTheme('dark', 'violet');
    const reset = ruleFor(':where([lang]:not(html))');

    expect(declaration(reset, '--font-ui')).toBe(withoutSpaceAfterCommas(typography.ui));
    expect(declaration(reset, '--font-mono')).toBe(withoutSpaceAfterCommas(typography.mono));
    expect(declaration(reset, '--tracking-scale')).toBe('1');
    expect(declaration(reset, '--script-line-height')).toBe('0');
    expect(declaration(reset, '--script-size-boost')).toBe('0px');
  });

  it('declares the language reset before the reorders and script tokens it yields to', () => {
    const reset = rules.findIndex(rule => rule.selector === ':where([lang]:not(html))');

    expect(reset).toBeLessThan(rules.findIndex(rule => rule.selector === '[lang]:lang(ja)'));
    expect(reset).toBeLessThan(rules.findIndex(rule => rule.selector === '[data-script~="no-tracking"]'));
  });

  it('gives typed values the body size with zero tracking and room for stacked marks', () => {
    const fields = ruleFor('input,textarea');

    expect(declaration(fields, 'font-family')).toBe('var(--font-ui)');
    expect(declaration(fields, 'font-size')).toBe('var(--text-body-size)');
    expect(declaration(fields, 'font-weight')).toBe('var(--text-body-weight)');
    expect(declaration(fields, 'letter-spacing')).toBe('0');
    expect(declaration(fields, 'line-height')).toBe('2');
  });
});
