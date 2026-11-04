import { afterEach, describe, expect, it } from 'vitest';
import { cleanup } from '@testing-library/react';
import styled from 'styled-components';
import { textRole, type TextRole } from '../mixins';
import { declaration, renderWithCss, rulesForElement, type CssRule } from './css-text';

function roleRule(role: TextRole): CssRule {
  const Probe = styled.div`
    ${textRole(role)}
  `;
  const { container, cssText } = renderWithCss(<Probe />);
  const rules = rulesForElement(container.firstElementChild!, cssText);

  expect(rules).toHaveLength(1);

  return rules[0];
}

describe('textRole', () => {
  afterEach(() => {
    cleanup();
  });

  it('reads the label tokens and scales its tracking by the script tracking scale', () => {
    const rule = roleRule('label');

    expect(declaration(rule, 'font-family')).toBe('var(--font-ui)');
    expect(declaration(rule, 'font-size')).toBe('calc(var(--text-label-size) + var(--script-size-boost, 0px))');
    expect(declaration(rule, 'font-weight')).toBe('var(--text-label-weight)');
    expect(declaration(rule, 'letter-spacing')).toBe('calc(var(--text-label-tracking) * var(--tracking-scale, 1))');
    expect(declaration(rule, 'line-height')).toBe('max(var(--text-label-line-height), var(--script-line-height, 0))');
    expect(declaration(rule, 'text-transform')).toBe('var(--text-label-transform)');
  });

  it('sets the mono family and tabular numerals for the timecode role', () => {
    const rule = roleRule('timecode');

    expect(declaration(rule, 'font-family')).toBe('var(--font-mono)');
    expect(declaration(rule, 'font-variant-numeric')).toBe('tabular-nums');
    expect(declaration(rule, 'font-size')).toBe('calc(var(--text-timecode-size) + var(--script-size-boost, 0px))');
  });

  it('keeps proportional numerals and no case transform on the body role', () => {
    const rule = roleRule('body');

    expect(declaration(rule, 'font-family')).toBe('var(--font-ui)');
    expect(declaration(rule, 'font-variant-numeric')).toBeUndefined();
    expect(declaration(rule, 'text-transform')).toBeUndefined();
  });
});
