import { useTranslation } from 'react-i18next'
import { useEffect, useRef, useState } from 'react'
import { request, requireSuccess, csrfToken } from '../lib/api'
import ContentLanguageSelect from './ContentLanguageSelect'
import { validationMessage } from '../lib/validation'
import SeoFields from './SeoFields'
import { seoFormFields, seoPayload } from '../lib/seo'

export default function DraftForm({ onCreated, onSessionExpired }) {
  const { t } = useTranslation()
  const [fields, setFields] = useState({ title: '', topic: '', tone: '', length: '', content_language: 'en', ...seoFormFields() })
  const [busy, setBusy] = useState(false)
  const pending = useRef(false)
  const mounted = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => { mounted.current = false }
  }, [])
  const [status, setStatus] = useState('')
  const [error, setError] = useState('')
  const [validationErrors, setValidationErrors] = useState({})

  async function createDraft(event) {
    event.preventDefault()
    if (pending.current) return
    pending.current = true
    setBusy(true)
    setError('')
    setValidationErrors({})
    setStatus('Creating draft...')

    const payload = { title: fields.title.trim(), topic: fields.topic.trim(), content_language: fields.content_language, ...seoPayload(fields) }
    for (const field of ['tone', 'length']) {
      if (fields[field].trim()) payload[field] = fields[field].trim()
    }

    try {
      const response = await request('/api/contents', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        body: JSON.stringify(payload),
      })
      await requireSuccess(response)
      await response.json()
      if (!mounted.current) return
      onCreated()
      setStatus('Draft created successfully.')
    } catch (failure) {
      if (!mounted.current) return
      if (failure.status === 401) {
        onSessionExpired()
        return
      }
      setStatus('Draft creation could not be confirmed.')
      setError(failure.status === 419 ? 'Your session needs to be refreshed. Log in again.' : failure.status === 422 ? 'Please check the highlighted fields.' : 'Could not create your draft. Please try again.')
      setValidationErrors(failure.errors || {})
    } finally {
      pending.current = false
      if (mounted.current) setBusy(false)
    }
  }

  return (
    <section className="panel draft-panel" aria-labelledby="draft-heading">
      <h2 id="draft-heading">{t('Create a draft')}</h2>
      <p className="muted">{t('Give your idea a little direction.')}</p>
      <form onSubmit={createDraft}>
        {['title', 'topic', 'tone', 'length'].map((field) => (
          <div className="draft-field" key={field}>
            <label htmlFor={`draft-${field}`}>
              {t(field === 'length' ? 'Article length' : field.charAt(0).toUpperCase() + field.slice(1))}
              {['tone', 'length'].includes(field) && t(' (optional)')}
            </label>
            <input id={`draft-${field}`} name={field} type="text" maxLength={255}
              translate="no" className="notranslate"
              required={['title', 'topic'].includes(field)} disabled={busy}
              value={fields[field]}
              onChange={(event) => setFields({ ...fields, [field]: event.target.value })}
              aria-invalid={Boolean(validationErrors[field])}
              aria-describedby={validationErrors[field] ? `draft-${field}-error` : undefined} />
            {validationErrors[field] && (
              <p id={`draft-${field}-error`} role="alert">{validationMessage(validationErrors[field], t)}</p>
            )}
          </div>
        ))}
        <SeoFields idPrefix="draft" fields={fields} onChange={setFields} disabled={busy} errors={validationErrors} />
        <ContentLanguageSelect id="draft-content-language" value={fields.content_language} disabled={busy}
          onChange={(event) => setFields({ ...fields, content_language: event.target.value })}
          errors={validationErrors.content_language} />
        <button className="primary" type="submit" disabled={busy}>{busy ? t('Creating...') : t('Create draft')}</button>
      </form>
      <p role="status">{status && t(status)}</p>
      {error && <p role="alert">{t(error)}</p>}
    </section>
  )
}
