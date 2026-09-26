export default function ContentCard({ content, generation, onGenerate }) {
  const generated = content.generated_content !== null
  return (
    <article className="panel content-card">
      <div className="card-heading">
        <h3>{content.title}</h3>
        <span className={`badge ${generated ? 'completed' : 'draft'}`}>{generated ? 'Generated' : 'Draft'}</span>
      </div>
      <p className="content-topic">{content.topic}</p>
      <dl className="content-details">
        <div><dt>Tone</dt><dd>{content.tone || 'Not specified'}</dd></div>
        <div><dt>Length</dt><dd>{content.length || 'Not specified'}</dd></div>
        <div><dt>Created</dt><dd><time dateTime={content.created_at}>
          {new Date(content.created_at).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })}
        </time></dd></div>
      </dl>
      {generated ? (
        <div className="generated-preview">
          <h4>Generated content</h4>
          <div className="generated-content">{content.generated_content}</div>
        </div>
      ) : (
        <button className="primary" type="button" disabled={generation?.pending} onClick={onGenerate}>
          {generation?.pending ? 'Generating...' : 'Generate'}
        </button>
      )}
      {generation?.pending && <p role="status">Generating your content. This may take a moment.</p>}
      {generation?.error && <p role="alert">{generation.error}</p>}
    </article>
  )
}
