import { useTranslation } from 'react-i18next'

const metrics = [
  ['provider_calls', 'Provider calls'], ['completed_provider_calls', 'Completed'],
  ['failed_provider_calls', 'Failed'], ['pending_provider_calls', 'Pending'],
  ['input_tokens', 'Input tokens'], ['output_tokens', 'Output tokens'], ['total_tokens', 'Total tokens'],
  ['unknown_usage_calls', 'Calls with unknown usage'], ['unknown_cost_calls', 'Calls with unknown cost'],
]
const operations = { generation: 'Generation', input_moderation: 'Input moderation', output_moderation: 'Output moderation' }

export default function AiUsagePanel({ usage }) {
  const { t, i18n } = useTranslation()
  const number = value => value.toLocaleString(i18n.resolvedLanguage)
  const cost = value => value == null ? '—' : Number(value).toLocaleString(i18n.resolvedLanguage, {
    style: 'currency', currency: usage.currency, minimumFractionDigits: 6, maximumFractionDigits: 8,
  })

  return (
    <section aria-labelledby="provider-usage-heading">
      <h2 id="provider-usage-heading">{t('AI usage and estimated cost')}</h2>
      <p className="muted">{t('Provider totals include both moderation stages and generation. Periods use UTC. Unknown usage and costs are excluded from known totals.')}</p>
      {Object.entries({ today: 'Today', month: 'Current month', all_time: 'All time' }).map(([period, label]) => {
        const values = usage.periods[period]
        return (
          <section key={period} aria-labelledby={`usage-${period}-heading`}>
            <h3 id={`usage-${period}-heading`}>{t(label)}</h3>
            <dl className="stats-grid">
              <div className="panel stat-card"><dt>{t('AI requests')}</dt><dd>{number(values.logical_requests.total)}</dd></div>
              {['completed', 'failed', 'pending'].map(status => (
                <div className="panel stat-card" key={status}><dt>{t('Logical requests: {{status}}', { status: t({ completed: 'Completed', failed: 'Failed', pending: 'Pending' }[status]) })}</dt><dd>{number(values.logical_requests[status])}</dd></div>
              ))}
              {metrics.map(([key, title]) => (
                <div className="panel stat-card" key={key}><dt>{t(title)}</dt><dd>{number(values[key])}</dd></div>
              ))}
              <div className="panel stat-card"><dt>{t('Known estimated cost ({{currency}})', { currency: usage.currency })}</dt><dd>{cost(values.estimated_cost)}</dd></div>
            </dl>
          </section>
        )
      })}
      <section className="panel recent-panel" aria-labelledby="operation-heading">
        <h3 id="operation-heading">{t('Provider calls by operation (all time)')}</h3>
        <div className="table-scroll" role="region" aria-label={t('Provider calls by operation (all time)')} tabIndex={0}>
          <table>
            <thead><tr>{['Operation', 'Provider calls', 'Input tokens', 'Output tokens', 'Total tokens', 'Estimated cost', 'Calls with unknown usage', 'Calls with unknown cost'].map(label => <th scope="col" key={label}>{t(label)}</th>)}</tr></thead>
            <tbody>{Object.entries(operations).map(([operation, label]) => {
              const values = usage.operations[operation]
              return <tr key={operation}><th scope="row">{t(label)}</th>
                {['provider_calls', 'input_tokens', 'output_tokens', 'total_tokens'].map(key => <td className="numeric" key={key}>{number(values[key])}</td>)}
                <td className="numeric">{cost(values.estimated_cost)}</td>
                <td className="numeric">{number(values.unknown_usage_calls)}</td><td className="numeric">{number(values.unknown_cost_calls)}</td>
              </tr>
            })}</tbody>
          </table>
        </div>
      </section>
    </section>
  )
}
