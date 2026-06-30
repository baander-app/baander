import type { Theme, ThemeMood, AccentColor, ThemeColors } from './theme.types';
import { ACCENT_PALETTE, isDarkMood } from './theme.types';

const MOOD_COLORS: Record<ThemeMood, ThemeColors> = {
  dark: {
    background: '#000000', card: '#0a0a0b', cardForeground: '#f3f4f6',
    popover: '#0a0a0b', popoverForeground: '#f3f4f6',
    foreground: '#f0f0f2', mutedForeground: '#8b8d97',
    primary: '#e4e4e7', primaryForeground: '#000000', ring: '#e4e4e7',
    secondary: '#141514', secondaryForeground: '#f0f0f2',
    muted: '#141514', highlight: '#141514', highlightForeground: '#f0f0f2',
    destructive: '#ef4444', destructiveForeground: '#f0f0f2',
    sidebar: '#080809', sidebarForeground: '#f0f0f2', sidebarBorder: 'transparent',
    border: '#1a1a1f', input: '#1a1a1f',
    chart1: '#e4e4e7', chart2: '#8b8d97', chart3: '#60a5fa', chart4: '#a78bfa', chart5: '#fb7185',
  },
  warm: {
    background: '#faf5ee', card: '#f5ede0', cardForeground: '#3d3225',
    popover: '#f5ede0', popoverForeground: '#3d3225',
    foreground: '#3d3225', mutedForeground: '#8a7e70',
    primary: '#8b6914', primaryForeground: '#faf5ee', ring: '#8b6914',
    secondary: '#efe5d5', secondaryForeground: '#3d3225',
    muted: '#efe5d5', highlight: '#efe5d5', highlightForeground: '#3d3225',
    destructive: '#c53030', destructiveForeground: '#faf5ee',
    sidebar: '#f2e8d8', sidebarForeground: '#3d3225', sidebarBorder: 'transparent',
    border: '#e0d5c5', input: '#e0d5c5',
    chart1: '#e4e4e7', chart2: '#8b8d97', chart3: '#60a5fa', chart4: '#a78bfa', chart5: '#fb7185',
  },
  cool: {
    background: '#f0f4f8', card: '#e8edf3', cardForeground: '#2d3748',
    popover: '#e8edf3', popoverForeground: '#2d3748',
    foreground: '#2d3748', mutedForeground: '#718096',
    primary: '#3182ce', primaryForeground: '#f0f4f8', ring: '#3182ce',
    secondary: '#dce4ed', secondaryForeground: '#2d3748',
    muted: '#dce4ed', highlight: '#dce4ed', highlightForeground: '#2d3748',
    destructive: '#c53030', destructiveForeground: '#f0f4f8',
    sidebar: '#e2e9f1', sidebarForeground: '#2d3748', sidebarBorder: 'transparent',
    border: '#cdd7e3', input: '#cdd7e3',
    chart1: '#e4e4e7', chart2: '#8b8d97', chart3: '#60a5fa', chart4: '#a78bfa', chart5: '#fb7185',
  },
  balanced: {
    background: '#f5f5f5', card: '#ebebeb', cardForeground: '#2d2d2d',
    popover: '#ebebeb', popoverForeground: '#2d2d2d',
    foreground: '#2d2d2d', mutedForeground: '#737373',
    primary: '#525252', primaryForeground: '#f5f5f5', ring: '#525252',
    secondary: '#e0e0e0', secondaryForeground: '#2d2d2d',
    muted: '#e0e0e0', highlight: '#e0e0e0', highlightForeground: '#2d2d2d',
    destructive: '#c53030', destructiveForeground: '#f5f5f5',
    sidebar: '#e5e5e5', sidebarForeground: '#2d2d2d', sidebarBorder: 'transparent',
    border: '#d4d4d4', input: '#d4d4d4',
    chart1: '#e4e4e7', chart2: '#8b8d97', chart3: '#60a5fa', chart4: '#a78bfa', chart5: '#fb7185',
  },
};

const STATIC_TOKENS = {
  radii: { sm: '0.375rem', md: '0.5rem', lg: '0.75rem', xl: '1rem', '2xl': '1.125rem', '3xl': '1.375rem', '4xl': '1.625rem' },
  spacing: { xs: '0.25rem', sm: '0.5rem', md: '1rem', lg: '1.5rem', xl: '2rem', '2xl': '3rem', '3xl': '4rem' },
  typography: { sans: '"Inter", system-ui, -apple-system, BlinkMacSystemFont, sans-serif', mono: '"JetBrains Mono", ui-monospace, monospace', heading: '"Inter", system-ui, -apple-system, BlinkMacSystemFont, sans-serif' },
  durations: { hover: '60ms', fast: '150ms', normal: '200ms', slow: '350ms' },
} as const;

export function resolveTheme(mood: ThemeMood, accent: AccentColor): Theme {
  const moodColors = { ...MOOD_COLORS[mood] };
  const accentColor = ACCENT_PALETTE[accent];
  moodColors.primary = accentColor;
  moodColors.ring = accentColor;

  return {
    colors: moodColors,
    radii: { ...STATIC_TOKENS.radii },
    spacing: { ...STATIC_TOKENS.spacing },
    typography: { ...STATIC_TOKENS.typography },
    durations: { ...STATIC_TOKENS.durations },
    _meta: { mood, accent, isDark: isDarkMood(mood) },
  };
}
