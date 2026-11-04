import { createGlobalStyle } from 'styled-components';
import { keyframes } from './keyframes';
import { textRole } from './mixins';

export const GlobalStyles = createGlobalStyle`
  *, *::before, *::after {
    box-sizing: border-box;
  }

  button, input, select, textarea {
    font: inherit;
    color: inherit;
  }

  /* Typed values carry no language, so they cannot get script rules: zero tracking
     and a line-height that keeps stacked Thai and Burmese marks visible. */
  input, textarea {
    font-family: var(--font-ui);
    font-size: var(--text-body-size);
    font-weight: var(--text-body-weight);
    letter-spacing: 0;
    line-height: 2;
  }

  input[type="checkbox"], input[type="radio"], input[type="range"] {
    accent-color: var(--color-primary);
  }

  body {
    margin: 0;
    ${textRole('body')}
    background-color: ${({ theme }) => theme.colors.background};
    color: ${({ theme }) => theme.colors.foreground};
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }

  /* Each element with its own language starts from the default font order and no
     script adjustment. Zero specificity lets the rules below override it on the
     same element; the root keeps the order the interface locale gives it. */
  :where([lang]:not(html)) {
    --font-ui: ${({ theme }) => theme.typography.ui};
    --font-mono: ${({ theme }) => theme.typography.mono};
    --tracking-scale: 1;
    --script-line-height: 0;
    --script-size-boost: 0px;
  }

  /* The element's language decides which Han glyph forms win. */
  [lang]:lang(ja) {
    --font-ui: var(--font-ui-ja);
    --font-mono: var(--font-mono-ja);
    line-break: strict;
  }

  [lang]:lang(ko) {
    --font-ui: var(--font-ui-ko);
    --font-mono: var(--font-mono-ko);
  }

  [lang]:lang(zh-Hant), [lang]:lang(zh-TW), [lang]:lang(zh-HK), [lang]:lang(zh-MO) {
    --font-ui: var(--font-ui-hant);
    --font-mono: var(--font-mono-hant);
  }

  /* Script rules come only from the characters an element renders. The
     line-height minimums ascend so the most demanding script present wins. */
  [data-script~="no-tracking"] {
    --tracking-scale: 0;
  }

  [data-script~="cjk"] {
    --script-line-height: 1.6;
    overflow-wrap: anywhere;
  }

  [data-script~="thai"] {
    --script-line-height: 1.8;
  }

  [data-script~="burmese"] {
    --script-line-height: 2;
  }

  [data-script~="burmese-only"] {
    --script-size-boost: 2px;
  }

  html.theme-transitioning,
  html.theme-transitioning *,
  html.theme-transitioning *::before,
  html.theme-transitioning *::after {
    transition-property: background-color, border-color, color, fill, stroke, box-shadow;
    transition-duration: 120ms;
    transition-timing-function: ease-out;
  }

  button, [role="button"], [role="switch"], [role="tab"], [role="slider"],
  [role="menuitem"], [role="option"], [role="checkbox"], [role="radio"],
  [role="link"], a[href], summary, select,
  [type="button"], [type="submit"], [type="reset"], label[for] {
    cursor: pointer;
  }

  button, [role="button"], [role="switch"], [role="slider"],
  [role="checkbox"], [role="radio"], [role="link"], a[href], summary, select,
  [type="button"], [type="submit"], [type="reset"] {
    transition: color var(--duration-hover) ease-out,
                background-color var(--duration-hover) ease-out,
                border-color var(--duration-hover) ease-out,
                opacity var(--duration-hover) ease-out;
  }

  * {
    scrollbar-width: thin;
    scrollbar-color: ${({ theme }) => theme.colors.border} transparent;
  }
  *::-webkit-scrollbar { width: 6px; height: 6px; }
  *::-webkit-scrollbar-track { background: transparent; }
  *::-webkit-scrollbar-thumb { background: ${({ theme }) => theme.colors.border}; border-radius: 9999px; }
  *::-webkit-scrollbar-thumb:hover { background: ${({ theme }) => theme.colors.mutedForeground}; }
  *::-webkit-scrollbar-corner { background: transparent; }

  @media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
  }

  ${keyframes}
`;
