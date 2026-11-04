import { scriptProfile, type ScriptProfile } from './script-profile';

interface StoredLanguage {
  /** The tag the element carries. */
  readonly tag: string;
  /** The primary language subtag, used to tell Chinese, Japanese and Korean apart. */
  readonly language: string;
}

const CJK_LANGUAGES: ReadonlySet<string> = new Set(['zh', 'ja', 'ko']);
/** Codes that are valid but name no single language, so the text decides instead. */
const NO_SINGLE_LANGUAGE: ReadonlySet<string> = new Set(['und', 'mul', 'zxx', 'mis']);
const TRADITIONAL_CHINESE_REGIONS: ReadonlySet<string> = new Set(['TW', 'HK', 'MO']);
const TRADITIONAL_CHINESE: StoredLanguage = { tag: 'zh-Hant', language: 'zh' };
/** TMDB reports Cantonese-language films with this non-ISO code. */
const TMDB_CANTONESE = 'cn';

let languageNames: Intl.DisplayNames | undefined;
/** Parsed stored languages by their raw value; a library repeats the same few codes. */
const storedLanguages = new Map<string, StoredLanguage | undefined>();

function isKnownLanguage(language: string): boolean {
  // Browsers without language display names cannot confirm a code; the canonical
  // two- or three-letter shape has already been checked, so accept it.
  if (typeof Intl.DisplayNames !== 'function') return true;
  languageNames ??= new Intl.DisplayNames(['en'], { type: 'language', fallback: 'none' });

  return languageNames.of(language) !== undefined;
}

/**
 * Reads a stored language value as an ISO 639-1, 639-2/B or /T, 639-3 or BCP 47
 * code. Locale canonicalisation maps three-letter codes such as `jpn`, `chi` and
 * `bur` to their two-letter forms. Free text such as `Japanese` and codes that
 * name no single language are not usable.
 */
function storedLanguage(stored: string | null | undefined): StoredLanguage | undefined {
  if (stored == null) {
    return undefined;
  }

  if (!storedLanguages.has(stored)) {
    storedLanguages.set(stored, parseStoredLanguage(stored));
  }

  return storedLanguages.get(stored);
}

function parseStoredLanguage(stored: string): StoredLanguage | undefined {
  const value = stored.trim();

  if (!value) {
    return undefined;
  }

  if (value.toLowerCase() === TMDB_CANTONESE) {
    return TRADITIONAL_CHINESE;
  }

  let locale: Intl.Locale;

  try {
    locale = new Intl.Locale(value);
  } catch {
    return undefined;
  }

  const { language, script, region } = locale;

  if (language.length > 3 || NO_SINGLE_LANGUAGE.has(language) || !isKnownLanguage(language)) {
    return undefined;
  }

  const traditionalChinese = language === 'zh'
    && (script === 'Hant' || (script === undefined && region !== undefined && TRADITIONAL_CHINESE_REGIONS.has(region)));

  if (language === 'yue' || traditionalChinese) {
    return TRADITIONAL_CHINESE;
  }

  return { tag: locale.toString(), language };
}

function languageFromKanaOrHangul(profile: ScriptProfile): string | undefined {
  if (profile.kana) {
    return 'ja';
  }

  if (profile.hangul) {
    return 'ko';
  }

  return undefined;
}

function detectedLanguage(profile: ScriptProfile): string | undefined {
  const kanaOrHangul = languageFromKanaOrHangul(profile);

  if (kanaOrHangul !== undefined) {
    return kanaOrHangul;
  }

  if (profile.thai) {
    return 'th';
  }

  if (profile.burmese) {
    return 'my';
  }

  if (profile.cyrillic) {
    return 'und-Cyrl';
  }

  return undefined;
}

/**
 * Resolves the `lang` of an element holding one content item's text. A usable
 * stored language wins, except that kana or Hangul in the text overrides one
 * outside Chinese, Japanese and Korean. Without one, the text's script decides,
 * and text with nothing to detect takes the interface locale, which keeps the
 * default Han glyph order.
 */
export function resolveContentLanguage(
  text: string,
  stored: string | null | undefined,
  interfaceLocale: string,
  profile: ScriptProfile = scriptProfile(text),
): string {
  const fromStore = storedLanguage(stored);

  if (fromStore !== undefined) {
    if (CJK_LANGUAGES.has(fromStore.language)) {
      return fromStore.tag;
    }

    return languageFromKanaOrHangul(profile) ?? fromStore.tag;
  }

  return detectedLanguage(profile) ?? interfaceLocale;
}
