import { THEMES } from '../../../../design-tokens/build/web/theme';
import type { Theme, ThemeMood, AccentColor } from './theme.types';

export function resolveTheme(mood: ThemeMood, accent: AccentColor): Theme {
  return THEMES[mood][accent];
}
