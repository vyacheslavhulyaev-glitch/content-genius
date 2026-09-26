import { useEffect, useState } from 'react'
import { request, requireSuccess } from '../lib/api'

const metrics = [
  ['total_users', 'Total users'], ['total_contents', 'Total contents'],
  ['generated_contents', 'Generated'], ['total_ai_requests', 'AI requests'],
  ['completed_ai_requests', 'Completed'], ['failed_ai_requests', 'Failed'],
  ['pending_ai_requests', 'Pending'], ['total_tokens_used', 'Tokens used'],
]

export default function AdminPage({ onSessionExpired }) {
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
        <p className="eyebrow">Administration</p>
        <h1>Activity overview</h1>
        <p className="muted">Content and AI activity across all users, for all time.</p>
      </div>
      {error && <p role="alert">{error}</p>}
      {!data && !error && <p className="panel" role="status">Loading dashboard...</p>}
      {data && (
        <>
          <dl className="stats-grid">
            {metrics.map(([key, label]) => (
              <div className="panel stat-card" key={key}><dt>{label}</dt><dd>{data.summary[key].toLocaleString()}</dd></div>
            ))}
          </dl>
          <section className="panel recent-panel" aria-labelledby="recent-heading">
            <div className="section-heading"><h2 id="recent-heading">Recent AI requests</h2><span className="muted">Latest 20</span></div>
            {data.recent_ai_requests.length === 0 ? (
              <div className="empty-state"><h3>No AI requests yet</h3><p>Generation activity will appear here once users get started.</p></div>
            ) : (
              <div className="table-scroll" role="region" aria-label="Recent AI requests" tabIndex={0}>
                <table>
                  <caption className="sr-only">Latest AI requests across all users</caption>
                  <thead><tr>{['Request', 'User', 'Content', 'Status', 'Tokens', 'Created'].map((label) => <th scope="col" key={label}>{label}</th>)}</tr></thead>
                  <tbody>
                    {data.recent_ai_requests.map((item) => (
                      <tr key={item.id}>
                        <td>#{item.id}</td>
                        <td><strong>{item.user.name}</strong><span className="table-email">{item.user.email}</span></td>
                        <td>{item.content?.title ?? 'Deleted / unavailable'}</td>
                        <td><span className={`badge ${item.status}`}>{item.status}</span></td>
                        <td className="numeric">{item.tokens_used?.toLocaleString() ?? '—'}</td>
                        <td><time dateTime={item.created_at}>{new Date(item.created_at).toLocaleString()}</time></td>
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
