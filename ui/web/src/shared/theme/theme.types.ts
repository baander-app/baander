export type {
  Theme, ThemeMood, AccentColor, ThemeColors, ThemeRadii, ThemeSpacing,
  ThemeTypography, ThemeDurations, ThemeMeta,
} from '../../../../design-tokens/build/web/theme';
export {
  VALID_MOODS, VALID_ACCENTS, ACCENT_PALETTE, MOOD_OPTIONS, ACCENT_OPTIONS,
} from '../../../../design-tokens/build/web/theme';

import type { ThemeMood } from '../../../../design-tokens/build/web/theme';

export function isDarkMood(mood: ThemeMood): boolean {
  return mood === 'dark';
}
