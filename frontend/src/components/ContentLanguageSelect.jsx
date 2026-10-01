import { useTranslation } from 'react-i18next'
import { contentLanguages } from '../lib/contentLanguages'
import { validationMessage } from '../lib/validation'

export default function ContentLanguageSelect({ id, value, onChange, disabled, errors, disabledLanguages = [] }) {
  const { t } = useTranslation()

  return (
    <div className="draft-field">
      <label htmlFor={id}>{t('Content language')}</label>
      <select id={id} name="content_language" value={value} onChange={onChange} disabled={disabled}
        required aria-invalid={Boolean(errors)} aria-describedby={errors ? `${id}-error` : undefined}>
        {contentLanguages.map((language) => (
          <option key={language.value} value={language.value} lang={language.value}
            disabled={disabledLanguages.includes(language.value)}>{language.label}</option>
        ))}
      </select>
      {errors && <p id={`${id}-error`} role="alert">{validationMessage(errors, t)}</p>}
    </div>
  )
}
