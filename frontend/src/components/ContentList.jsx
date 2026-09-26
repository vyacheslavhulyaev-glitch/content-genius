import { useEffect, useRef, useState } from 'react'
import { request, requireSuccess, csrfToken } from '../lib/api'
import ContentCard from './ContentCard'

export default function ContentList({ onSessionExpired, refreshVersion }) {
  const [contents, setContents] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [generations, setGenerations] = useState({})
  const mounted = useRef(false)
  useEffect(() => {
    mounted.current = true
    return () => { mounted.current = false }
  }, [])
  const pendingGenerations = useRef(new Set())

  useEffect(() => {
    let active = true

    async function loadContents() {
      try {
        const response = await request('/api/contents')
        await requireSuccess(response)
        const result = await response.json()
        if (active) {
          setContents((current) => result.map((content) => (
            current.find((item) => item.id === content.id && item.generated_content !== null) || content
          )))
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

  async function generateContent(content) {
    if (content.generated_content !== null || pendingGenerations.current.has(content.id)) return

    pendingGenerations.current.add(content.id)
    setGenerations((current) => ({ ...current, [content.id]: { pending: true, error: '' } }))

    try {
      const response = await request(`/api/contents/${content.id}/generate`, {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': csrfToken() },
      })
      await requireSuccess(response)
      const result = await response.json()
      if (!mounted.current) return
      setContents((current) => current.map((item) => item.id === content.id ? result.content : item))
      setGenerations((current) => ({ ...current, [content.id]: { pending: false, error: '' } }))
    } catch (failure) {
      if (!mounted.current) return
      if (failure.status === 401) {
        onSessionExpired()
        return
      }
      const message = failure.status === 409
        ? 'This content is already generated or generation is pending.'
        : failure.status === 503
          ? 'The AI service is currently unavailable.'
          : 'Could not complete the generation request.'
      setGenerations((current) => ({ ...current, [content.id]: { pending: false, error: message } }))
    } finally {
      pendingGenerations.current.delete(content.id)
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
            <ContentCard content={content} generation={generations[content.id]}
              onGenerate={() => generateContent(content)} />
          </li>
        ))}
      </ul>
    </section>
  )
}
