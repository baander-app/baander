export type ThemeMood = 'dark' | 'warm' | 'cool' | 'balanced';
export type AccentColor = 'white' | 'blue' | 'violet' | 'rose' | 'amber' | 'emerald' | 'cyan' | 'pink';

export const VALID_MOODS: readonly ThemeMood[] = ['dark', 'warm', 'cool', 'balanced'] as const;
export const VALID_ACCENTS: readonly AccentColor[] = ['white', 'blue', 'violet', 'rose', 'amber', 'emerald', 'cyan', 'pink'] as const;

export interface ThemeColors {
  readonly background: string;
  readonly card: string;
  readonly cardForeground: string;
  readonly popover: string;
  readonly popoverForeground: string;
  readonly foreground: string;
  readonly mutedForeground: string;
  readonly primary: string;
  readonly primaryForeground: string;
  readonly ring: string;
  readonly secondary: string;
  readonly secondaryForeground: string;
  readonly muted: string;
  readonly highlight: string;
  readonly highlightForeground: string;
  readonly destructive: string;
  readonly destructiveForeground: string;
  readonly sidebar: string;
  readonly sidebarForeground: string;
  readonly sidebarBorder: string;
  readonly border: string;
  readonly input: string;
  readonly chart1: string;
  readonly chart2: string;
  readonly chart3: string;
  readonly chart4: string;
  readonly chart5: string;
}

export interface ThemeRadii {
  readonly sm: string;
  readonly md: string;
  readonly lg: string;
  readonly xl: string;
  readonly '2xl': string;
  readonly '3xl': string;
  readonly '4xl': string;
}

export interface ThemeSpacing {
  readonly xs: string;
  readonly sm: string;
  readonly md: string;
  readonly lg: string;
  readonly xl: string;
  readonly '2xl': string;
  readonly '3xl': string;
}

export interface ThemeTypography {
  readonly sans: string;
  readonly mono: string;
  readonly heading: string;
}

export interface ThemeDurations {
  readonly hover: string;
  readonly fast: string;
  readonly normal: string;
  readonly slow: string;
}

export interface ThemeMeta {
  readonly mood: ThemeMood;
  readonly accent: AccentColor;
  readonly isDark: boolean;
}

export interface Theme {
  readonly colors: ThemeColors;
  readonly radii: ThemeRadii;
  readonly spacing: ThemeSpacing;
  readonly typography: ThemeTypography;
  readonly durations: ThemeDurations;
  readonly _meta: ThemeMeta;
}

export function isDarkMood(mood: ThemeMood): boolean {
  return mood === 'dark';
}

export const ACCENT_PALETTE: Record<AccentColor, string> = {
  white: '#e4e4e7',
  blue: '#60a5fa',
  violet: '#a78bfa',
  rose: '#fb7185',
  amber: '#fbbf24',
  emerald: '#34d399',
  cyan: '#22d3ee',
  pink: '#f0abfc',
} as const;
