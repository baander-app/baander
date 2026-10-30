import { describe, expect, it } from 'vitest';
import { THEMES } from '../../../../../design-tokens/build/web/theme';
import { resolveTheme } from '../resolve-theme';
import { VALID_MOODS, VALID_ACCENTS, MOOD_OPTIONS, ACCENT_OPTIONS, ACCENT_PALETTE } from '../theme.types';

describe('generated runtime theme', () => {
  for (const mood of VALID_MOODS) {
    for (const accent of VALID_ACCENTS) {
      it(`resolves ${mood}/${accent} from the generated table`, () => {
        const theme = resolveTheme(mood, accent);
        expect(theme).toBe(THEMES[mood][accent]);
        expect(theme._meta).toEqual({ mood, accent, isDark: mood === 'dark' });
        expect(theme.colors.primaryForeground).toBe(mood === 'dark' ? '#000000' : '#ffffff');
      });
    }
  }

  it('uses generated appearance options and preserves accent identities', () => {
    expect(MOOD_OPTIONS.map(option => option.value)).toEqual(VALID_MOODS);
    expect(ACCENT_OPTIONS.map(option => option.value)).toEqual(VALID_ACCENTS);
    for (const option of MOOD_OPTIONS) {
      const colors = resolveTheme(option.value, 'violet').colors;
      expect(option.preview).toEqual({ bg: colors.background, fg: colors.foreground, card: colors.card });
    }
    for (const option of ACCENT_OPTIONS) expect(option.hex).toBe(ACCENT_PALETTE[option.value]);
    expect(resolveTheme('warm', 'violet').colors.accentText).not.toBe(ACCENT_PALETTE.violet);
  });
});
