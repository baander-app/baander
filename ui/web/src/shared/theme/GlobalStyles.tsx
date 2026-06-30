import { createGlobalStyle } from 'styled-components';
import { keyframes } from './keyframes';

export const GlobalStyles = createGlobalStyle`
  /* === Inject CSS variables from theme for Tailwind coexistence === */
  :root {
    --color-background: ${({ theme }) => theme.colors.background};
    --color-card: ${({ theme }) => theme.colors.card};
    --color-card-foreground: ${({ theme }) => theme.colors.cardForeground};
    --color-popover: ${({ theme }) => theme.colors.popover};
    --color-popover-foreground: ${({ theme }) => theme.colors.popoverForeground};
    --color-foreground: ${({ theme }) => theme.colors.foreground};
    --color-muted-foreground: ${({ theme }) => theme.colors.mutedForeground};
    --color-primary: ${({ theme }) => theme.colors.primary};
    --color-primary-foreground: ${({ theme }) => theme.colors.primaryForeground};
    --color-ring: ${({ theme }) => theme.colors.ring};
    --color-secondary: ${({ theme }) => theme.colors.secondary};
    --color-secondary-foreground: ${({ theme }) => theme.colors.secondaryForeground};
    --color-muted: ${({ theme }) => theme.colors.muted};
    --color-highlight: ${({ theme }) => theme.colors.highlight};
    --color-highlight-foreground: ${({ theme }) => theme.colors.highlightForeground};
    --color-destructive: ${({ theme }) => theme.colors.destructive};
    --color-destructive-foreground: ${({ theme }) => theme.colors.destructiveForeground};
    --color-sidebar: ${({ theme }) => theme.colors.sidebar};
    --color-sidebar-foreground: ${({ theme }) => theme.colors.sidebarForeground};
    --color-sidebar-border: ${({ theme }) => theme.colors.sidebarBorder};
    --color-border: ${({ theme }) => theme.colors.border};
    --color-input: ${({ theme }) => theme.colors.input};
    --color-chart-1: ${({ theme }) => theme.colors.chart1};
    --color-chart-2: ${({ theme }) => theme.colors.chart2};
    --color-chart-3: ${({ theme }) => theme.colors.chart3};
    --color-chart-4: ${({ theme }) => theme.colors.chart4};
    --color-chart-5: ${({ theme }) => theme.colors.chart5};
    /* Coexistence aliases — removed in Phase 23 */
    --color-accent: ${({ theme }) => theme.colors.highlight};
    --color-accent-foreground: ${({ theme }) => theme.colors.highlightForeground};
    --radius-sm: ${({ theme }) => theme.radii.sm};
    --radius-md: ${({ theme }) => theme.radii.md};
    --radius-lg: ${({ theme }) => theme.radii.lg};
    --radius-xl: ${({ theme }) => theme.radii.xl};
    --radius-2xl: ${({ theme }) => theme.radii['2xl']};
    --radius-3xl: ${({ theme }) => theme.radii['3xl']};
    --radius-4xl: ${({ theme }) => theme.radii['4xl']};
    --space-xs: ${({ theme }) => theme.spacing.xs};
    --space-sm: ${({ theme }) => theme.spacing.sm};
    --space-md: ${({ theme }) => theme.spacing.md};
    --space-lg: ${({ theme }) => theme.spacing.lg};
    --space-xl: ${({ theme }) => theme.spacing.xl};
    --space-2xl: ${({ theme }) => theme.spacing['2xl']};
    --space-3xl: ${({ theme }) => theme.spacing['3xl']};
    --font-sans: ${({ theme }) => theme.typography.sans};
    --font-mono: ${({ theme }) => theme.typography.mono};
    --font-heading: ${({ theme }) => theme.typography.heading};
    --duration-hover: ${({ theme }) => theme.durations.hover};
    --duration-fast: ${({ theme }) => theme.durations.fast};
    --duration-normal: ${({ theme }) => theme.durations.normal};
    --duration-slow: ${({ theme }) => theme.durations.slow};
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
    transition-duration: 200ms;
    transition-timing-function: ease-out;
  }

  button, [role="button"], [role="switch"], [role="tab"], [role="slider"],
  [role="menuitem"], [role="option"], [role="checkbox"], [role="radio"],
  [role="link"], a[href], summary, select,
  [type="button"], [type="submit"], [type="reset"], label[for] {
    cursor: pointer;
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
    * { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
  }

  ${keyframes}
`;
