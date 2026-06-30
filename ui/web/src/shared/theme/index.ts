// Phase 3: types + resolver
export type {
  Theme, ThemeMood, AccentColor, ThemeColors, ThemeRadii,
  ThemeSpacing, ThemeTypography, ThemeDurations, ThemeMeta,
} from './theme.types';
export { resolveTheme } from './resolve-theme';
export { ACCENT_PALETTE, VALID_MOODS, VALID_ACCENTS, isDarkMood } from './theme.types';

// Phase 4: ThemeProvider + GlobalStyles
export { ThemeProvider } from './ThemeProvider';
export { GlobalStyles } from './GlobalStyles';

// Phase 4: Mixins (used by primitives in Phase 6+)
export {
  focusVisibleRing, interactiveTransition, inputFocusRing,
  disabledStyle, ariaInvalidRing, darkMode,
} from './mixins';
