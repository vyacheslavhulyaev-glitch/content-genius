import { useTranslation } from 'react-i18next'

const languages = [
  { value: 'en', label: 'EN', name: 'English' },
  { value: 'uk', label: 'UA', name: 'Українська' },
  { value: 'de', label: 'DE', name: 'Deutsch' },
]

export default function LanguageSwitcher() {
  const { t, i18n } = useTranslation()

  return (
    <div className="language-switcher" role="group" aria-label={t('Interface language')}>
      {languages.map(({ value, label, name }) => (
        <button key={value} type="button" lang={value} aria-label={name}
          aria-pressed={i18n.resolvedLanguage === value} onClick={() => i18n.changeLanguage(value)}>
          {label}
        </button>
      ))}
    </div>
  )
}
