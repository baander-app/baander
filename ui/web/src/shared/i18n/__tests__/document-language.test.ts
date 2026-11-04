import { afterEach, describe, expect, it, vi } from 'vitest';

const thaiBrowserDetector = {
  type: 'languageDetector' as const,
  init: () => {},
  detect: () => 'th',
  cacheUserLanguage: () => {},
};

describe('document language', () => {
  afterEach(() => {
    vi.doUnmock('i18next-browser-languagedetector');
    vi.resetModules();
  });

  it('follows the resolved interface language rather than the detected browser locale', async () => {
    vi.resetModules();
    vi.doMock('i18next-browser-languagedetector', () => ({ default: thaiBrowserDetector }));
    document.documentElement.lang = '';

    await import('@/shared/i18n');
    const { default: i18n } = await import('i18next');

    await vi.waitFor(() => {
      expect(i18n.isInitialized).toBe(true);
    });

    expect(i18n.language).toBe('th');
    expect(i18n.resolvedLanguage).toBe('en');
    expect(document.documentElement.lang).toBe('en');

    i18n.addResourceBundle('de', 'translation', { greeting: 'Hallo' });
    await i18n.changeLanguage('de');

    expect(document.documentElement.lang).toBe('de');
  });
});
