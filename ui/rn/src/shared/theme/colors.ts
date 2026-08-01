/**
 * Baander design tokens -- RN constants.
 *
 * Source of truth: ui/DESIGN.md
 * These are RN StyleSheet-compatible values, not CSS variables.
 */

export const colors = {
  // Surfaces
  background: '#000000',
  card: '#0a0a0b',
  sidebar: '#080809',
  border: '#1a1a1f',

  // Text
  foreground: '#ffffff',
  muted: '#8b8d97',

  // Interactive
  primary: '#3b82f6',
  secondary: '#1e293b',
  accent: '#1e40af',
  destructive: '#ef4444',

  // Overlays
  overlay: 'rgba(0, 0, 0, 0.4)',

  // Transparent
  transparent: 'transparent',
} as const;

export type ColorToken = keyof typeof colors;
