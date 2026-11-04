import { describe, expect, it } from 'vitest';
import { scriptProfile, scriptTokens } from '../script-profile';

function tokensFor(text: string): string[] {
  return scriptTokens(scriptProfile(text));
}

describe('scriptProfile', () => {
  it('marks a Thai label for zero tracking and Thai line-height, and leaves its Latin twin alone', () => {
    expect(tokensFor('ฤดูกาล 2')).toEqual(['no-tracking', 'thai']);
    expect(tokensFor('Season 2')).toEqual([]);
  });

  it('enlarges only a string written wholly in Burmese', () => {
    expect(tokensFor('ဆက်ကြည့်ရန်')).toEqual(['no-tracking', 'burmese', 'burmese-only']);
    expect(tokensFor('Season 1 · စီးဇင်း 1')).toEqual(['no-tracking', 'burmese']);
  });

  it('treats digits, spaces and punctuation as neutral in a wholly Burmese string', () => {
    expect(tokensFor('ဆက်ကြည့်ရန် · 2')).toEqual(['no-tracking', 'burmese', 'burmese-only']);
  });

  it('gives Han, kana and Hangul the CJK token', () => {
    expect(tokensFor('继续观看')).toEqual(['no-tracking', 'cjk']);
    expect(tokensFor('シーズン2')).toEqual(['no-tracking', 'cjk']);
    expect(tokensFor('직')).toEqual(['no-tracking', 'cjk']);
  });

  it('reports every script in a mixed string so the most demanding line-height wins', () => {
    expect(tokensFor('Season 1 · 第1季 · シーズン1 · Сезон 1 · ฤดูกาล 1 · စီးဇင်း 1'))
      .toEqual(['no-tracking', 'cjk', 'thai', 'burmese']);
  });

  it('reports which scripts the text contains', () => {
    expect(scriptProfile('続きを見る')).toMatchObject({ kana: true, han: true, hangul: false });
    expect(scriptProfile('Продолжить просмотр')).toMatchObject({ cyrillic: true, thai: false });
    expect(scriptProfile('')).toEqual({
      thai: false,
      burmese: false,
      burmeseOnly: false,
      hangul: false,
      kana: false,
      han: false,
      cyrillic: false,
    });
  });

  it('returns one shared, frozen empty profile for text with no character past Greek', () => {
    const latin = scriptProfile('Beyoncé - Déjà Vu');

    expect(latin).toBe(scriptProfile('Αλκηστις'));
    expect(Object.isFrozen(latin)).toBe(true);
    expect(scriptTokens(latin)).toEqual([]);
  });
});
