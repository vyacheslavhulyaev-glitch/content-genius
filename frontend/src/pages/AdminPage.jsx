import { useTranslation } from 'react-i18next'
import { useEffect, useState } from 'react'
import { request, requireSuccess } from '../lib/api'
import AiUsagePanel from '../components/AiUsagePanel'

const metrics = [
  ['total_users', 'Total users'], ['total_contents', 'Total contents'],
  ['generated_contents', 'Generated'], ['total_ai_requests', 'AI requests'],
  ['completed_ai_requests', 'Completed'], ['failed_ai_requests', 'Failed'],
  ['pending_ai_requests', 'Pending'],
]

export default function AdminPage({ onSessionExpired }) {
  const { t, i18n } = useTranslation()
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  useEffect(() => {
    let active = true
    async function load() {
      try {
        const response = await request('/api/admin/dashboard')
        await requireSuccess(response)
        const result = await response.json()
        if (active) setData(result)
      } catch (failure) {
        if (!active) return
        if (failure.status === 401) {
          onSessionExpired()
          return
        }
        setError(failure.status === 403 ? 'Access denied. You do not have permission to view this page.'
          : 'Could not load the admin dashboard. Please try opening this page again.')
      }
    }
    load()
    return () => { active = false }
  }, [onSessionExpired])

  return (
    <>
      <div className="page-heading">
        <p className="eyebrow">{t('Administration')}</p>
        <h1>{t('Activity overview')}</h1>
        <p className="muted">{t('Content and AI activity across all users, for all time.')}</p>
      </div>
      {error && <p role="alert">{t(error)}</p>}
      {!data && !error && <p className="panel" role="status">{t('Loading dashboard...')}</p>}
      {data && (
        <>
          <dl className="stats-grid">
            {metrics.map(([key, label]) => (
              <div className="panel stat-card" key={key}><dt>{t(label)}</dt><dd>{data.summary[key].toLocaleString(i18n.resolvedLanguage)}</dd></div>
            ))}
          </dl>
          {data.provider_usage && <AiUsagePanel usage={data.provider_usage} />}
          <section className="panel recent-panel" aria-labelledby="recent-heading">
            <div className="section-heading"><h2 id="recent-heading">{t('Recent AI requests')}</h2><span className="muted">{t('Latest 20')}</span></div>
            {data.recent_ai_requests.length === 0 ? (
              <div className="empty-state"><h3>{t('No AI requests yet')}</h3><p>{t('Generation activity will appear here once users get started.')}</p></div>
            ) : (
              <div className="table-scroll" role="region" aria-label={t('Recent AI requests')} tabIndex={0}>
                <table>
                  <caption className="sr-only">{t('Latest AI requests across all users')}</caption>
                  <thead><tr>{['Request', 'User', 'Content', 'Status', 'Tokens', 'Created'].map((label) => <th scope="col" key={label}>{t(label)}</th>)}</tr></thead>
                  <tbody>
                    {data.recent_ai_requests.map((item) => (
                      <tr key={item.id}>
                        <td>#{item.id}</td>
                        <td><strong>{item.user.name}</strong><span className="table-email">{item.user.email}</span></td>
                        <td>{item.content?.title != null ? <span translate="no" className="notranslate">{item.content.title}</span> : t('Deleted / unavailable')}</td>
                        <td><span className={`badge ${item.status}`}>{t({ completed: 'Completed', failed: 'Failed', pending: 'Pending' }[item.status], { defaultValue: item.status })}</span></td>
                        <td className="numeric">{item.tokens_used?.toLocaleString(i18n.resolvedLanguage) ?? '—'}</td>
                        <td><time dateTime={item.created_at}>{new Date(item.created_at).toLocaleString(i18n.resolvedLanguage)}</time></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </>
      )}
    </>
  )
}
