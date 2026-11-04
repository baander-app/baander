/** Which scripts a string contains, as far as the typography rules care. */
export interface ScriptProfile {
  readonly thai: boolean;
  readonly burmese: boolean;
  /** Burmese apart from digits, spaces and punctuation. */
  readonly burmeseOnly: boolean;
  readonly hangul: boolean;
  readonly kana: boolean;
  readonly han: boolean;
  readonly cyrillic: boolean;
}

/** A token the global styles map to script adjustments, written to `data-script`. */
export type ScriptToken = 'no-tracking' | 'cjk' | 'thai' | 'burmese' | 'burmese-only';

const THAI = /\p{Script=Thai}/u;
const BURMESE = /\p{Script=Myanmar}/u;
const BURMESE_AND_NEUTRAL_ONLY = /^[\p{Script=Myanmar}\p{N}\p{P}\p{Z}\s\u200B-\u200D]*$/u;
const HANGUL = /\p{Script=Hangul}/u;
const KANA = /[\p{Script=Hiragana}\p{Script=Katakana}]/u;
const HAN = /\p{Script=Han}/u;
const CYRILLIC = /\p{Script=Cyrillic}/u;
/** Any character past Greek; every script the rules detect lies above it. */
const BEYOND_GREEK = /[\u0400-\u{10FFFF}]/u;

const NO_SCRIPTS: ScriptProfile = Object.freeze({
  thai: false,
  burmese: false,
  burmeseOnly: false,
  hangul: false,
  kana: false,
  han: false,
  cyrillic: false,
});

export function scriptProfile(text: string): ScriptProfile {
  if (!BEYOND_GREEK.test(text)) {
    return NO_SCRIPTS;
  }

  const burmese = BURMESE.test(text);

  return {
    thai: THAI.test(text),
    burmese,
    burmeseOnly: burmese && BURMESE_AND_NEUTRAL_ONLY.test(text),
    hangul: HANGUL.test(text),
    kana: KANA.test(text),
    han: HAN.test(text),
    cyrillic: CYRILLIC.test(text),
  };
}

/**
 * Turns a profile into the element's script tokens. Any Thai, Burmese, Hangul or
 * CJK character removes tracking for the whole element; each script present adds
 * its line-height token, and the global styles let the most demanding one win.
 */
export function scriptTokens(profile: ScriptProfile): ScriptToken[] {
  const cjk = profile.han || profile.kana || profile.hangul;
  const tokens: ScriptToken[] = [];

  if (cjk || profile.thai || profile.burmese) {
    tokens.push('no-tracking');
  }

  if (cjk) {
    tokens.push('cjk');
  }

  if (profile.thai) {
    tokens.push('thai');
  }

  if (profile.burmese) {
    tokens.push('burmese');
  }

  if (profile.burmeseOnly) {
    tokens.push('burmese-only');
  }

  return tokens;
}
