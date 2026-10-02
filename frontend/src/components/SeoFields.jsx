import { useTranslation } from 'react-i18next'
import { validationMessage } from '../lib/validation'
import { maxLinks } from '../lib/seo'
import { generationLimits as limits } from '../lib/generationLimits'

export default function SeoFields({ idPrefix, fields, onChange, disabled, errors = {} }) {
  const { t } = useTranslation()
  const messages = name => Object.entries(errors).filter(([key]) => key === name || key.startsWith(`${name}.`)).flatMap(([, values]) => values)
  const error = name => {
    const values = messages(name)
    return values.length ? <p id={`${idPrefix}-${name}-error`} role="alert">{validationMessage(values, t)}</p> : null
  }
  const editLink = (index, field, value) => onChange({ ...fields,
    links: fields.links.map((link, current) => current === index ? { ...link, [field]: value } : link),
  })

  return (
    <>
      {[
        ['primary_keyword', 'Primary keyword', limits.primary_keyword, false],
        ['secondary_keywords', 'Secondary keywords', limits.secondary_keywords * (limits.secondary_keyword + 1) - 1, true],
        ['meta_title', 'Meta title guidance', limits.meta_title, false],
        ['meta_description', 'Meta description guidance', limits.meta_description, true],
      ].map(([name, label, maxLength, multiline]) => {
        const Input = multiline ? 'textarea' : 'input'
        return (
          <div className="draft-field" key={name}>
            <label htmlFor={`${idPrefix}-${name}`}>{t(label)}{name !== 'primary_keyword' && t(' (optional)')}</label>
            <Input id={`${idPrefix}-${name}`} name={name} maxLength={maxLength}
              translate="no" className="notranslate" required={name === 'primary_keyword'}
              disabled={disabled} value={fields[name]} rows={multiline ? 3 : undefined}
              onChange={event => onChange({ ...fields, [name]: event.target.value })}
              aria-invalid={messages(name).length > 0}
              aria-describedby={messages(name).length ? `${idPrefix}-${name}-error` : undefined} />
            {name === 'secondary_keywords' && <small className="muted">{t('One keyword per line, up to {{max}}. Keywords guide generation; they are not meta tags.', { max: limits.secondary_keywords })}</small>}
            {error(name)}
          </div>
        )
      })}
      <fieldset className="seo-links">
        <legend>{t('Article links')}{t(' (optional)')}</legend>
        <p className="muted">{t('Up to {{max}} unique HTTP or HTTPS URLs with contextual anchor text.', { max: maxLinks })}</p>
        {fields.links.map((link, index) => (
          <div className="seo-link-row" key={index}>
            {['anchor', 'url'].map(name => (
              <div className="draft-field" key={name}>
                <label htmlFor={`${idPrefix}-link-${index}-${name}`}>{t(name === 'anchor' ? 'Anchor text' : 'URL')}</label>
                <input id={`${idPrefix}-link-${index}-${name}`} name={`links.${index}.${name}`}
                  type={name === 'url' ? 'url' : 'text'} pattern={name === 'url' ? '[Hh][Tt][Tt][Pp][Ss]?://.+' : undefined}
                  maxLength={limits[name]} required disabled={disabled}
                  translate="no" className="notranslate" value={link[name]}
                  onChange={event => editLink(index, name, event.target.value)}
                  aria-invalid={Boolean(errors[`links.${index}.${name}`])}
                  aria-describedby={errors[`links.${index}.${name}`] ? `${idPrefix}-links-error` : undefined} />
              </div>
            ))}
            <button className="secondary" type="button" disabled={disabled}
              aria-label={t('Remove link {{number}}', { number: index + 1 })}
              onClick={() => onChange({ ...fields, links: fields.links.filter((_, current) => current !== index) })}>{t('Remove link')}</button>
          </div>
        ))}
        {error('links')}
        <button className="secondary" type="button" disabled={disabled || fields.links.length >= maxLinks}
          onClick={() => onChange({ ...fields, links: [...fields.links, { anchor: '', url: '' }] })}>{t('Add link')}</button>
      </fieldset>
    </>
  )
}
