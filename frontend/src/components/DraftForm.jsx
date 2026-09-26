import { useEffect, useRef, useState } from 'react'
import { request, requireSuccess, csrfToken } from '../lib/api'

export default function DraftForm({ onCreated, onSessionExpired }) {
  const [fields, setFields] = useState({ title: '', topic: '', tone: '', length: '' })
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

    const payload = { title: fields.title.trim(), topic: fields.topic.trim() }
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
      <h2 id="draft-heading">Create a draft</h2>
      <p className="muted">Give your idea a little direction.</p>
      <form onSubmit={createDraft}>
        {['title', 'topic', 'tone', 'length'].map((field) => (
          <div className="draft-field" key={field}>
            <label htmlFor={`draft-${field}`}>
              {field.charAt(0).toUpperCase() + field.slice(1)}
              {['tone', 'length'].includes(field) && ' (optional)'}
            </label>
            <input id={`draft-${field}`} name={field} type="text" maxLength={255}
              required={['title', 'topic'].includes(field)} disabled={busy}
              value={fields[field]}
              onChange={(event) => setFields({ ...fields, [field]: event.target.value })}
              aria-invalid={Boolean(validationErrors[field])}
              aria-describedby={validationErrors[field] ? `draft-${field}-error` : undefined} />
            {validationErrors[field] && (
              <p id={`draft-${field}-error`} role="alert">{validationErrors[field].join(' ')}</p>
            )}
          </div>
        ))}
        <button className="primary" type="submit" disabled={busy}>{busy ? 'Creating...' : 'Create draft'}</button>
      </form>
      <p role="status">{status}</p>
      {error && <p role="alert">{error}</p>}
    </section>
  )
}
