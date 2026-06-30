/**
 * Baander typography tokens.
 *
 * Source of truth: ui/DESIGN.md
 * Inter font family. RN uses fontWeight/string for variants.
 */

export const fontFamilies = {
  sans: 'Inter',
  mono: 'JetBrains Mono',
} as const;

export const fontSizes = {
  /** 11px -- labels, uppercase tracking */
  label: 11,
  /** 13px -- small body */
  sm: 13,
  /** 14px -- body text */
  body: 14,
  /** 16px -- large body */
  lg: 16,
  /** 20px -- small heading */
  xl: 20,
  /** 24px -- heading */
  '2xl': 24,
  /** 30px -- large heading */
  '3xl': 30,
  /** 36px -- display */
  '4xl': 36,
} as const;

export const fontWeights = {
  normal: '400' as const,
  medium: '500' as const,
  semibold: '600' as const,
} as const;

export const lineHeights = {
  tight: 1.2,
  normal: 1.5,
  relaxed: 1.75,
} as const;

export const tracking = {
  tight: -0.01,
  wider: 0.05,
} as const;
