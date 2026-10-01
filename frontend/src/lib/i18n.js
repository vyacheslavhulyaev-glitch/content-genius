import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import en from '../locales/en.json'
import uk from '../locales/uk.json'
import de from '../locales/de.json'

const storageKey = 'contentgenius.ui-language'
const supportedLanguages = ['en', 'uk', 'de']

function initialLanguage() {
  try {
    const saved = localStorage.getItem(storageKey)
    if (supportedLanguages.includes(saved)) return saved
  } catch {
    // The interface also works when browser storage is unavailable.
  }
  return 'en'
}

i18n.on('languageChanged', (language) => {
  document.documentElement.lang = language
  try {
    localStorage.setItem(storageKey, language)
  } catch {
    // Keep the current selection in memory when storage is unavailable.
  }
})

i18n.use(initReactI18next).init({
  resources: { en: { translation: en }, uk: { translation: uk }, de: { translation: de } },
  lng: initialLanguage(),
  fallbackLng: 'en',
  supportedLngs: supportedLanguages,
  keySeparator: false,
  nsSeparator: false,
  interpolation: { escapeValue: false },
})

export default i18n
