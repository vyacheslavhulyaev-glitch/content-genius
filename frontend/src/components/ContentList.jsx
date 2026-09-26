import { useEffect, useRef, useState } from 'react'
import { request, requireSuccess, csrfToken } from '../lib/api'
import ContentCard from './ContentCard'

export default function ContentList({ onSessionExpired, refreshVersion }) {
  const [contents, setContents] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [actions, setActions] = useState({})
  const mounted = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => { mounted.current = false }
  }, [])
  const pendingActions = useRef(new Set())
  const revisions = useRef(new Map())

  useEffect(() => {
    let active = true
    const startedRevisions = new Map(revisions.current)

    async function loadContents() {
      try {
        const response = await request('/api/contents')
        await requireSuccess(response)
        const result = await response.json()
        if (active) {
          setContents((current) => result.map((content) => (
            (revisions.current.get(content.id) ?? 0) !== (startedRevisions.get(content.id) ?? 0)
              ? current.find((item) => item.id === content.id)
              : content
          )).filter(Boolean))
          setError('')
        }
      } catch (failure) {
        if (!active) return
        if (failure.status === 401) {
          onSessionExpired()
          return
        }
        setError('Could not load your content. Reload the page to try again.')
      } finally {
        if (active) setLoading(false)
      }
    }

    loadContents()
    return () => { active = false }
  }, [onSessionExpired, refreshVersion])

  async function mutateContent(content, operation, fields) {
    if (pendingActions.current.has(content.id)) return false
    if (operation === 'delete' && !window.confirm(`Delete "${content.title}"? This cannot be undone.`)) return false

    pendingActions.current.add(content.id)
    setActions((current) => ({ ...current, [content.id]: { pending: operation, error: '', errors: {} } }))
    try {
      const generation = operation === 'generate' || operation === 'regenerate'
      const response = await request(`/api/contents/${content.id}${generation ? `/${operation}` : ''}`, {
        method: generation ? 'POST' : operation === 'edit' ? 'PATCH' : 'DELETE',
        headers: { 'X-XSRF-TOKEN': csrfToken(), ...(operation === 'edit' ? { 'Content-Type': 'application/json' } : {}) },
        ...(operation === 'edit' ? { body: JSON.stringify(fields) } : {}),
      })
      await requireSuccess(response)
      const result = operation === 'delete' ? null : await response.json()
      if (!mounted.current) return false
      revisions.current.set(content.id, (revisions.current.get(content.id) ?? 0) + 1)
      setContents((current) => operation === 'delete'
        ? current.filter((item) => item.id !== content.id)
        : current.map((item) => item.id === content.id ? (generation ? result.content : result) : item))
      setActions((current) => ({ ...current, [content.id]: { pending: false, error: '', errors: {} } }))
      return true
    } catch (failure) {
      if (!mounted.current) return false
      if (failure.status === 401) {
        onSessionExpired()
        return false
      }
      const message = failure.status === 409
        ? 'This action conflicts with the current content state, or generation is already pending. Reload to check the latest state.'
        : failure.status === 503
          ? 'The AI service is currently unavailable. Your existing text has been kept.'
          : failure.status === 422 && operation === 'edit'
            ? 'Please check the highlighted fields.'
            : 'Could not complete the request. Please try again.'
      setActions((current) => ({ ...current, [content.id]: {
        pending: false, error: message, errors: operation === 'edit' ? failure.errors || {} : {},
      } }))
      return false
    } finally {
      pendingActions.current.delete(content.id)
    }
  }

  return (
    <section aria-labelledby="contents-heading">
      <div className="section-heading"><h2 id="contents-heading">Your content</h2><span className="muted">{contents.length} items</span></div>
      {loading && <p role="status">Loading drafts...</p>}
      {error && <p role="alert">{error}</p>}
      {!loading && !error && contents.length === 0 && <div className="empty-state"><h3>Your next idea starts here</h3><p>Create your first draft, then generate content when you are ready.</p></div>}
      <ul className="content-list">
        {contents.map((content) => (
          <li key={content.id}>
            <ContentCard content={content} action={actions[content.id]}
              onGenerate={() => mutateContent(content, content.generated_content === null ? 'generate' : 'regenerate')}
              onSave={(fields) => mutateContent(content, 'edit', fields)}
              onDelete={() => mutateContent(content, 'delete')}
              onClearError={() => setActions((current) => ({ ...current, [content.id]: { pending: false, error: '', errors: {} } }))} />
          </li>
        ))}
      </ul>
    </section>
  )
}
