import { useTranslation } from 'react-i18next'
import { useState } from 'react'
import ContentLanguageSelect from './ContentLanguageSelect'
import { contentLanguages } from '../lib/contentLanguages'
import { validationMessage } from '../lib/validation'
import { languageVersionStatus } from '../lib/contentVersions'

const versionIndicators = {
  missing: { icon: '+', description: 'No language version yet' },
  draft: { icon: '○', description: 'Language version exists, content not generated yet' },
  generated: { icon: '✓', description: 'Generated and up to date' },
  stale: { icon: '↻', description: 'Needs regeneration' },
  pending: { icon: '…', description: 'Generation in progress' },
}

export default function ContentCard({ content, action, isGenerating = false, generatingContentIds, groupBusy, onSelectVersion, onAddLanguage, onGenerate, onSave, onDelete, onClearError }) {
  const { t, i18n } = useTranslation()
  const [editing, setEditing] = useState(false)
  const [fields, setFields] = useState({})
  const generated = content.generated_content !== null
  const stale = generated && content.is_generation_stale
  const busy = Boolean(action?.pending) || isGenerating || groupBusy

  function startEditing() {
    setFields({ title: content.title, topic: content.topic, tone: content.tone || '', length: content.length || '', content_language: content.content_language })
    onClearError()
    setEditing(true)
  }

  async function save(event) {
    event.preventDefault()
    const saved = await onSave({
      title: fields.title.trim(), topic: fields.topic.trim(),
      tone: fields.tone.trim() || null, length: fields.length.trim() || null,
      content_language: fields.content_language,
    })
    if (saved) setEditing(false)
  }

  return (
    <article className="panel content-card">
      <div className="card-heading">
        <h3 translate="no" className="notranslate">{content.title}</h3>
        <span className={`badge ${stale ? 'pending' : generated ? 'completed' : 'draft'}`}>
          {stale ? t('Needs regeneration') : generated ? t('Generated') : t('Draft')}
        </span>
      </div>
      <p className="content-topic notranslate" translate="no">{content.topic}</p>
      <nav className="content-languages" aria-label={t('Language versions')}>
        <span>{t('Languages')}:</span>
        {contentLanguages.map((language) => {
          const version = content.translations.find((translation) => translation.content_language === language.value)
          const label = language.value === 'uk' ? 'UA' : language.value.toUpperCase()
          const status = languageVersionStatus(version, generatingContentIds)
          const indicator = versionIndicators[status]
          const accessibleLabel = t('{{action}}: {{status}}', {
            action: t(version ? 'Open {{language}} version' : 'Add {{language}} version', { language: language.label }),
            status: t(indicator.description),
          })
          return version ? (
            <button key={language.value} type="button" className="secondary" data-content-id={version.id}
              aria-current={version.id === content.id ? 'true' : undefined}
              aria-label={accessibleLabel} title={accessibleLabel}
              onClick={() => onSelectVersion(version.id)}>
              {label} <span aria-hidden="true">{indicator.icon}</span>
            </button>
          ) : (
            <button key={language.value} type="button" className="secondary" disabled={busy || editing}
              aria-label={accessibleLabel} title={accessibleLabel}
              onClick={() => onAddLanguage(language.value)}>
              {label} <span aria-hidden="true">{indicator.icon}</span>
            </button>
          )
        })}
      </nav>
      <dl className="content-details">
        <div><dt>{t('Tone')}</dt><dd>{content.tone ? <span translate="no" className="notranslate">{content.tone}</span> : t('Not specified')}</dd></div>
        <div><dt>{t('Length')}</dt><dd>{content.length ? <span translate="no" className="notranslate">{content.length}</span> : t('Not specified')}</dd></div>
        <div><dt>{t('Content language')}</dt><dd lang={content.content_language}>
          {contentLanguages.find((language) => language.value === content.content_language)?.label}
        </dd></div>
        <div><dt>{t('Primary language')}</dt><dd lang={content.primary_language}>
          {contentLanguages.find((language) => language.value === content.primary_language)?.label}
        </dd></div>
        <div><dt>{t('Created')}</dt><dd><time dateTime={content.created_at}>
          {new Date(content.created_at).toLocaleDateString(i18n.resolvedLanguage, { year: 'numeric', month: 'short', day: 'numeric' })}
        </time></dd></div>
      </dl>
      {editing && (
        <form className="inline-edit" onSubmit={save}>
          {['title', 'topic', 'tone', 'length'].map((field) => (
            <div className="draft-field" key={field}>
              <label htmlFor={`edit-${content.id}-${field}`}>
                {t(field.charAt(0).toUpperCase() + field.slice(1))}{['tone', 'length'].includes(field) && t(' (optional)')}
              </label>
              <input id={`edit-${content.id}-${field}`} name={field} type="text" maxLength={255}
                translate="no" className="notranslate"
                required={['title', 'topic'].includes(field)} disabled={busy} value={fields[field]}
                onChange={(event) => setFields({ ...fields, [field]: event.target.value })}
                aria-invalid={Boolean(action?.errors?.[field])}
                aria-describedby={action?.errors?.[field] ? `edit-${content.id}-${field}-error` : undefined} />
              {action?.errors?.[field] && <p role="alert" id={`edit-${content.id}-${field}-error`}>{validationMessage(action.errors[field], t)}</p>}
            </div>
          ))}
          <ContentLanguageSelect id={`edit-${content.id}-content-language`} value={fields.content_language} disabled={busy}
            disabledLanguages={content.translations.filter((version) => version.id !== content.id).map((version) => version.content_language)}
            onChange={(event) => setFields({ ...fields, content_language: event.target.value })}
            errors={action?.errors?.content_language} />
          <div className="card-actions">
            <button className="primary" type="submit" disabled={busy}>{action?.pending === 'edit' ? t('Saving...') : t('Save')}</button>
            <button className="secondary" type="button" disabled={busy} onClick={() => { setEditing(false); onClearError() }}>{t('Cancel')}</button>
          </div>
        </form>
      )}
      {stale && <p className="stale-notice">{t('Content settings were changed. The text below was generated using the previous settings.')}</p>}
      {!editing && (
        <div className="card-actions">
          <button className="secondary" type="button" disabled={busy} onClick={startEditing}>{t('Edit')}</button>
          <button className={!generated || stale ? 'primary' : 'secondary'} type="button" disabled={busy} onClick={onGenerate}>
            {isGenerating ? (generated ? t('Regenerating...') : t('Generating...')) : generated ? t('Regenerate') : t('Generate')}
          </button>
          <button className="danger" type="button" disabled={busy} onClick={onDelete}>{action?.pending === 'delete' ? t('Deleting...') : t('Delete')}</button>
        </div>
      )}
      {generated && (
        <div className="generated-preview">
          <h4>{t('Generated content')}</h4>
          <div className="generated-content notranslate" translate="no">{content.generated_content}</div>
        </div>
      )}
      {action?.pending && <p role="status">{action.pending === 'regenerate' ? t('Regenerating content. Your previous text remains visible.')
        : action.pending === 'translations' ? t('Adding language version...')
        : action.pending === 'generate' ? t('Generating your content. This may take a moment.')
          : action.pending === 'edit' ? t('Saving content settings...') : t('Deleting content...')}</p>}
      {action?.error && <p role="alert">{t(action.error)}</p>}
    </article>
  )
}
