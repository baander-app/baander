/** Every type role the design tokens define. */
export const TEXT_ROLES = [
  'hero', 'title-lg', 'title', 'subtitle', 'body', 'body-sm', 'caption', 'label', 'timecode', 'mono-sm',
] as const

export type TestStringKey = 'en' | 'vi' | 'th' | 'my' | 'sc' | 'tc' | 'jp' | 'ko' | 'ru' | 'mix'

export interface TestString {
  readonly key: TestStringKey
  readonly text: string
  /** The stored language the fixture gives the string; detection decides when absent. */
  readonly language?: string
}

/**
 * The typography specification's test strings, plus a Korean one so Hangul text is
 * covered. Traditional Chinese cannot be told from Simplified by its characters,
 * so it carries its stored language.
 */
export const TEST_STRINGS: readonly TestString[] = [
  { key: 'en', text: 'The quick brown fox — "Dune: Part Two" (2024)' },
  { key: 'vi', text: 'Tiếng Việt có dấu: động vật, ứng dụng, đường, ề, ộ, ư' },
  { key: 'th', text: 'รับชมต่อ · ฤดูกาล 2 ตอนที่ 3 · ภาษาไทยทดสอบการแสดงผล' },
  { key: 'my', text: 'ဆက်ကြည့်ရန် · မြန်မာစာ စမ်းသပ်ခြင်း · နေကောင်းပါသလား' },
  { key: 'sc', text: '继续观看 · 第3集《约定》· 简体中文显示测试' },
  { key: 'tc', text: '繼續觀看 · 第3集《約定》· 繁體中文顯示測試', language: 'zh-Hant' },
  { key: 'jp', text: '続きを見る · シーズン2 第3話「約束」· 日本語の表示テスト' },
  { key: 'ko', text: '계속 보기 · 시즌 2 제3화 · 한국어 표시 테스트' },
  { key: 'ru', text: 'Продолжить просмотр · Сезон 2, серия 3' },
  { key: 'mix', text: 'Season 1 · 第1季 · シーズン1 · Сезон 1 · ฤดูกาล 1 · စီးဇင်း 1' },
]

export const BURMESE_TITLE = 'ဆက်ကြည့်ရန်'
export const MIXED_BURMESE_TITLE = 'Season 1 · စီးဇင်း 1'
export const THAI_LABEL = 'ฤดูกาล 2'
export const LATIN_LABEL = 'Season 2'
