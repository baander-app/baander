import type { ReactElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { ServerStyleSheet, ThemeProvider } from 'styled-components';
import { resolveTheme } from '../resolve-theme';

export interface CssRule {
  readonly selector: string;
  readonly declarations: string;
}

export interface RenderedCss {
  readonly container: HTMLElement;
  readonly cssText: string;
}

/** Renders an element inside the generated theme and returns its markup with the CSS styled-components emitted. */
export function renderWithCss(element: ReactElement): RenderedCss {
  const sheet = new ServerStyleSheet();

  try {
    const container = document.createElement('div');

    container.innerHTML = renderToStaticMarkup(sheet.collectStyles(
      <ThemeProvider theme={resolveTheme('dark', 'violet')}>{element}</ThemeProvider>,
    ));

    const cssText = sheet.getStyleTags()
      .replace(/<\/?style[^>]*>/g, '')
      .replace(/\/\*[\s\S]*?\*\//g, '');

    return { container, cssText };
  } finally {
    sheet.seal();
  }
}

/** Splits CSS text into its innermost rules, in source order. */
export function cssRules(cssText: string): CssRule[] {
  return [...cssText.matchAll(/([^{}]+)\{([^{}]*)\}/g)].map(([, selector, declarations]) => ({
    selector: selector.trim(),
    declarations: declarations.trim(),
  }));
}

/** Returns the rules whose selector targets one of the element's classes. */
export function rulesForElement(element: Element, cssText: string): CssRule[] {
  const selectors = new Set([...element.classList].map(name => `.${name}`));

  return cssRules(cssText).filter(rule => selectors.has(rule.selector));
}

/** Reads one declaration's value from a rule's declaration block. */
export function declaration(rule: CssRule, property: string): string | undefined {
  for (const part of rule.declarations.split(';')) {
    const separator = part.indexOf(':');

    if (separator !== -1 && part.slice(0, separator).trim() === property) {
      return part.slice(separator + 1).trim();
    }
  }

  return undefined;
}
