import { afterEach, describe, expect, it, vi } from 'vitest';
import { resolveContentLanguage } from '../content-language';

const INTERFACE_LOCALE = 'en';

function resolve(text: string, stored?: string | null): string {
  return resolveContentLanguage(text, stored, INTERFACE_LOCALE);
}

describe('resolveContentLanguage', () => {
  it('uses a stored ISO 639-2 code for Han-only text', () => {
    expect(resolve('直', 'jpn')).toBe('ja');
  });

  it('falls back to the interface locale for Han-only text with no stored language', () => {
    expect(resolve('直')).toBe('en');
    expect(resolve('坂本龍一', null)).toBe('en');
    expect(resolveContentLanguage('周杰倫', undefined, 'de')).toBe('de');
  });

  it('detects Japanese from kana when the stored value is not a language code', () => {
    expect(resolve('続きを見る', 'Japanese')).toBe('ja');
  });

  it('lets kana or Hangul override a stored language outside Chinese, Japanese and Korean', () => {
    expect(resolve('続きを見る', 'en')).toBe('ja');
    expect(resolve('직', 'en')).toBe('ko');
  });

  it('keeps a stored CJK language when the text holds another script', () => {
    expect(resolve('รับชมต่อ', 'ja')).toBe('ja');
    expect(resolve('シーズン2', 'ko')).toBe('ko');
  });

  it('keeps a stored language outside CJK for text without kana or Hangul', () => {
    expect(resolve('รับชมต่อ', 'eng')).toBe('en');
    expect(resolve('Dune: Part Two', 'fre')).toBe('fr');
  });

  it('maps Traditional Chinese tags, Cantonese and the TMDB code to zh-Hant', () => {
    for (const stored of ['zh-TW', 'zh-HK', 'zh-MO', 'zh-Hant', 'yue', 'cn']) {
      expect(resolve('繼續觀看', stored), stored).toBe('zh-Hant');
    }
  });

  it('keeps plain Chinese codes on the default Simplified order', () => {
    expect(resolve('继续观看', 'zh')).toBe('zh');
    expect(resolve('继续观看', 'chi')).toBe('zh');
    expect(resolve('继续观看', 'zho')).toBe('zh');
  });

  it('recognises the Korean, Thai and Burmese code forms', () => {
    expect(resolve('직', 'kor')).toBe('ko');
    expect(resolve('รับชมต่อ', 'tha')).toBe('th');
    expect(resolve('ဆက်ကြည့်ရန်', 'mya')).toBe('my');
    expect(resolve('ဆက်ကြည့်ရန်', 'bur')).toBe('my');
  });

  it('detects the language from the text when nothing usable is stored', () => {
    expect(resolve('Продолжить просмотр')).toBe('und-Cyrl');
    expect(resolve('รับชมต่อ', '')).toBe('th');
    expect(resolve('ဆက်ကြည့်ရန်', 'mul')).toBe('my');
    expect(resolve('직', 'zxx')).toBe('ko');
    expect(resolve('シーズン2', 'xyz')).toBe('ja');
  });

  it('uses the interface locale for Latin text with no stored language', () => {
    expect(resolve('Season 2')).toBe('en');
    expect(resolve('')).toBe('en');
  });

  describe('stored language cache', () => {
    afterEach(() => {
      vi.restoreAllMocks();
    });

    it('parses a stored value once and returns the same result on a repeated call', () => {
      const lookup = vi.spyOn(Intl.DisplayNames.prototype, 'of');

      expect(resolve('Saudade', 'por')).toBe('pt');
      expect(lookup).toHaveBeenCalledTimes(1);

      expect(resolve('Saudade', 'por')).toBe('pt');
      expect(lookup).toHaveBeenCalledTimes(1);
    });
  });
  it('falls back to the text when the stored value is not a well-formed language tag', () => {
    expect(resolve('続きを見る', 'English (US)')).toBe('ja');
    expect(resolve('Love Song', 'English (US)')).toBe('en');
  });

  it('still recognises a stored code where the browser has no language display names', async () => {
    vi.stubGlobal('Intl', Object.assign(Object.create(Intl), { DisplayNames: undefined }));
    vi.resetModules();
    try {
      const { resolveContentLanguage: freshResolve } = await import('../content-language');
      expect(freshResolve('Kalevala', 'fin', INTERFACE_LOCALE)).toBe('fi');
    } finally {
      vi.unstubAllGlobals();
    }
  });
});
