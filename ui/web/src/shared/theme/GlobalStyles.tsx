import { createGlobalStyle } from 'styled-components';
import { keyframes } from './keyframes';

export const GlobalStyles = createGlobalStyle`
  *, *::before, *::after {
    box-sizing: border-box;
  }

  button, input, select, textarea {
    font: inherit;
    color: inherit;
  }

  input[type="checkbox"], input[type="radio"], input[type="range"] {
    accent-color: var(--color-primary);
  }

  body {
    margin: 0;
    font-family: ${({ theme }) => theme.typography.sans};
    background-color: ${({ theme }) => theme.colors.background};
    color: ${({ theme }) => theme.colors.foreground};
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    font-size: 0.875rem;
    line-height: 1.6;
    letter-spacing: -0.01em;
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
