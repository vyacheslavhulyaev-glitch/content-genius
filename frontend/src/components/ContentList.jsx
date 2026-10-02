import { useTranslation } from 'react-i18next'
import { useEffect, useRef, useState } from 'react'
import { request, requireSuccess, csrfToken, moderationErrorMessage, generationErrorMessage } from '../lib/api'
import ContentCard from './ContentCard'
import { mergeContentMutation, mergeContentSnapshot, selectedGroupContents } from '../lib/contentVersions'

export default function ContentList({ onSessionExpired, refreshVersion }) {
  const { t } = useTranslation()
  const [contents, setContents] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [actions, setActions] = useState({})
  const [selectedContentIds, setSelectedContentIds] = useState({})
  const [generatingContentIds, setGeneratingContentIds] = useState(() => new Set())
  const [reloadVersion, setReloadVersion] = useState(0)
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
          setContents((current) => mergeContentSnapshot(current, result, revisions.current, startedRevisions))
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
  }, [onSessionExpired, refreshVersion, reloadVersion])

  async function mutateContent(content, operation, fields) {
    if (pendingActions.current.has(content.content_group_id)) return false
    if (operation === 'delete' && !window.confirm(t('Delete "{{title}}"? This cannot be undone.', { title: content.title }))) return false

    pendingActions.current.add(content.content_group_id)
    const generation = operation === 'generate' || operation === 'regenerate'
    if (generation) setGeneratingContentIds((current) => new Set(current).add(content.id))
    setActions((current) => ({ ...current, [content.id]: { pending: operation, error: '', errors: {} } }))
    try {
      const hasBody = operation === 'edit' || operation === 'translations'
      const response = await request(`/api/contents/${content.id}${generation || operation === 'translations' ? `/${operation}` : ''}`, {
        method: generation || operation === 'translations' ? 'POST' : operation === 'edit' ? 'PATCH' : 'DELETE',
        headers: { 'X-XSRF-TOKEN': csrfToken(), ...(hasBody ? { 'Content-Type': 'application/json' } : {}) },
        ...(hasBody ? { body: JSON.stringify(fields) } : {}),
      })
      await requireSuccess(response)
      const result = operation === 'delete' ? null : await response.json()
      if (!mounted.current) return false
      revisions.current.set(content.content_group_id, (revisions.current.get(content.content_group_id) ?? 0) + 1)
      setContents((current) => mergeContentMutation(current, content, generation ? result.content : result))
      if (operation === 'translations') {
        setSelectedContentIds((current) => ({ ...current, [content.content_group_id]: result.id }))
      }
      setActions((current) => ({ ...current, [content.id]: { pending: false, error: '', errors: {} } }))
      if (!generation) setReloadVersion((version) => version + 1)
      return true
    } catch (failure) {
      if (!mounted.current) return false
      if (failure.status === 401) {
        onSessionExpired()
        return false
      }
      const message = generationErrorMessage(failure.code) ?? moderationErrorMessage(failure.code) ?? (failure.status === 422 && operation === 'translations'
        ? 'Could not add this language version. It may already exist. Reload to check the latest versions.'
        : failure.status === 409
          ? 'This action conflicts with the current content state, or generation is already pending. Reload to check the latest state.'
          : failure.status === 503
            ? 'The AI service is currently unavailable. Your existing text has been kept.'
            : failure.status === 422
              ? 'Please check the highlighted fields.'
              : 'Could not complete the request. Please try again.')
      setActions((current) => ({ ...current, [content.id]: {
        pending: false, error: message, errorValues: { minutes: Math.max(1, Math.ceil((failure.retryAfter ?? 60) / 60)) },
        errors: failure.errors || {},
      } }))
      return false
    } finally {
      pendingActions.current.delete(content.content_group_id)
      if (generation && mounted.current) {
        setGeneratingContentIds((current) => {
          const remaining = new Set(current)
          remaining.delete(content.id)
          return remaining
        })
      }
    }
  }

  const busyGroups = new Set(contents.filter((content) => actions[content.id]?.pending).map((content) => content.content_group_id))

  return (
    <section aria-labelledby="contents-heading">
      <div className="section-heading"><h2 id="contents-heading">{t('Your content')}</h2><span className="muted">{t('items', { count: contents.length })}</span></div>
      {loading && <p role="status">{t('Loading drafts...')}</p>}
      {error && <p role="alert">{t(error)}</p>}
      {!loading && !error && contents.length === 0 && <div className="empty-state"><h3>{t('Your next idea starts here')}</h3><p>{t('Create your first draft, then generate content when you are ready.')}</p></div>}
      <ul className="content-list">
        {selectedGroupContents(contents, selectedContentIds).map((content) => (
          <li key={content.content_group_id}>
            <ContentCard key={content.id} content={content} action={actions[content.id]}
              onSelectVersion={(id) => setSelectedContentIds((current) => ({ ...current, [content.content_group_id]: id }))}
              isGenerating={generatingContentIds.has(content.id)}
              generatingContentIds={generatingContentIds}
              groupBusy={busyGroups.has(content.content_group_id)}
              onAddLanguage={(language) => mutateContent(content, 'translations', { content_language: language })}
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
