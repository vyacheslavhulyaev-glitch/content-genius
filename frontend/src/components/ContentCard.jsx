import { useState } from 'react'

export default function ContentCard({ content, action, onGenerate, onSave, onDelete, onClearError }) {
  const [editing, setEditing] = useState(false)
  const [fields, setFields] = useState({})
  const generated = content.generated_content !== null
  const stale = generated && content.is_generation_stale
  const busy = Boolean(action?.pending)

  function startEditing() {
    setFields({ title: content.title, topic: content.topic, tone: content.tone || '', length: content.length || '' })
    onClearError()
    setEditing(true)
  }

  async function save(event) {
    event.preventDefault()
    const saved = await onSave({
      title: fields.title.trim(), topic: fields.topic.trim(),
      tone: fields.tone.trim() || null, length: fields.length.trim() || null,
    })
    if (saved) setEditing(false)
  }

  return (
    <article className="panel content-card">
      <div className="card-heading">
        <h3>{content.title}</h3>
        <span className={`badge ${stale ? 'pending' : generated ? 'completed' : 'draft'}`}>
          {stale ? 'Needs regeneration' : generated ? 'Generated' : 'Draft'}
        </span>
      </div>
      <p className="content-topic">{content.topic}</p>
      <dl className="content-details">
        <div><dt>Tone</dt><dd>{content.tone || 'Not specified'}</dd></div>
        <div><dt>Length</dt><dd>{content.length || 'Not specified'}</dd></div>
        <div><dt>Created</dt><dd><time dateTime={content.created_at}>
          {new Date(content.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })}
        </time></dd></div>
      </dl>
      {editing && (
        <form className="inline-edit" onSubmit={save}>
          {['title', 'topic', 'tone', 'length'].map((field) => (
            <div className="draft-field" key={field}>
              <label htmlFor={`edit-${content.id}-${field}`}>
                {field.charAt(0).toUpperCase() + field.slice(1)}{['tone', 'length'].includes(field) && ' (optional)'}
              </label>
              <input id={`edit-${content.id}-${field}`} name={field} type="text" maxLength={255}
                required={['title', 'topic'].includes(field)} disabled={busy} value={fields[field]}
                onChange={(event) => setFields({ ...fields, [field]: event.target.value })}
                aria-invalid={Boolean(action?.errors?.[field])}
                aria-describedby={action?.errors?.[field] ? `edit-${content.id}-${field}-error` : undefined} />
              {action?.errors?.[field] && <p role="alert" id={`edit-${content.id}-${field}-error`}>{action.errors[field].join(' ')}</p>}
            </div>
          ))}
          <div className="card-actions">
            <button className="primary" type="submit" disabled={busy}>{action?.pending === 'edit' ? 'Saving...' : 'Save'}</button>
            <button className="secondary" type="button" disabled={busy} onClick={() => { setEditing(false); onClearError() }}>Cancel</button>
          </div>
        </form>
      )}
      {stale && <p className="stale-notice">Content settings were changed. The text below was generated using the previous settings.</p>}
      {!editing && (
        <div className="card-actions">
          <button className="secondary" type="button" disabled={busy} onClick={startEditing}>Edit</button>
          <button className={!generated || stale ? 'primary' : 'secondary'} type="button" disabled={busy} onClick={onGenerate}>
            {action?.pending === 'regenerate' ? 'Regenerating...' : action?.pending === 'generate' ? 'Generating...' : generated ? 'Regenerate' : 'Generate'}
          </button>
          <button className="danger" type="button" disabled={busy} onClick={onDelete}>{action?.pending === 'delete' ? 'Deleting...' : 'Delete'}</button>
        </div>
      )}
      {generated && (
        <div className="generated-preview">
          <h4>Generated content</h4>
          <div className="generated-content">{content.generated_content}</div>
        </div>
      )}
      {action?.pending && <p role="status">{action.pending === 'regenerate' ? 'Regenerating content. Your previous text remains visible.'
        : action.pending === 'generate' ? 'Generating your content. This may take a moment.'
          : action.pending === 'edit' ? 'Saving content settings...' : 'Deleting content...'}</p>}
      {action?.error && <p role="alert">{action.error}</p>}
    </article>
  )
}
