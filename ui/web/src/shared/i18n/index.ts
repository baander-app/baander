import { useSyncExternalStore } from 'react'
import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import LanguageDetector from 'i18next-browser-languagedetector'
import en from './locales/en/translation.json'

const FALLBACK_LANGUAGE = 'en'

/**
 * The language the interface is shown in. The detector can report a browser locale
 * that has no translations, so the resolved language is the honest value.
 */
function interfaceLanguage(): string {
  return i18n.resolvedLanguage ?? FALLBACK_LANGUAGE
}

function subscribeToInterfaceLanguage(listener: () => void): () => void {
  i18n.on('languageChanged', listener)

  return () => {
    i18n.off('languageChanged', listener)
  }
}

function syncDocumentLanguage(): void {
  document.documentElement.lang = interfaceLanguage()
}

i18n.on('languageChanged', syncDocumentLanguage)

i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources: {
      en: { translation: en },
    },
    fallbackLng: FALLBACK_LANGUAGE,
    interpolation: {
      escapeValue: false,
    },
  })

/** The interface language, re-rendering the caller when it changes. */
export function useInterfaceLanguage(): string {
  return useSyncExternalStore(subscribeToInterfaceLanguage, interfaceLanguage, interfaceLanguage)
}

export { useTranslation } from 'react-i18next'
