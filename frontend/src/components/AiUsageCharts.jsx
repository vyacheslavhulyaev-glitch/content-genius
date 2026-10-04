import { useTranslation } from 'react-i18next'
import { Bar, BarChart, CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'

const operations = { generation: 'Generation', input_moderation: 'Input moderation', output_moderation: 'Output moderation' }

export default function AiUsageCharts({ usage }) {
  const { t, i18n } = useTranslation()
  const number = value => Number(value).toLocaleString(i18n.resolvedLanguage)
  const date = value => new Date(`${value}T00:00:00Z`).toLocaleDateString(i18n.resolvedLanguage, { month: 'short', day: 'numeric', timeZone: 'UTC' })
  const trend = usage.trend_7_days ?? []
  const breakdown = Object.entries(operations).map(([operation, label]) => ({ name: t(label), provider_calls: usage.operations?.[operation]?.provider_calls ?? 0 }))
  const hasTrend = trend.some(day => day.provider_calls > 0 || day.total_tokens > 0)
  const hasOperations = breakdown.some(item => item.provider_calls > 0)

  return (
    <section className="analytics-section" aria-labelledby="analytics-heading">
      <h2 id="analytics-heading">{t('Activity analytics')}</h2>
      <div className="analytics-grid">
        <section className="panel chart-panel" aria-labelledby="trend-heading">
          <h3 id="trend-heading">{t('AI usage — last 7 UTC days')}</h3>
          <p className="muted">{t('Calls use the left axis; tokens use the right axis. Unknown tokens are excluded.')}</p>
          <div className="chart-legend"><span className="calls-key">{t('Provider calls')} ({t('Left axis')})</span><span className="tokens-key">{t('Total tokens')} ({t('Right axis')})</span></div>
          {hasTrend ? <div className="chart-frame">
            <ResponsiveContainer width="100%" height="100%" minWidth={0} initialDimension={{ width: 480, height: 280 }}>
              <LineChart data={trend} accessibilityLayer margin={{ top: 12, right: 4, bottom: 8, left: 4 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#e0e5ed" />
                <XAxis dataKey="date" tickFormatter={date} minTickGap={16} tick={{ fontSize: 12 }} />
                <YAxis yAxisId="calls" allowDecimals={false} domain={[0, 'auto']} stroke="#4944a8" width={45} tickFormatter={number} />
                <YAxis yAxisId="tokens" orientation="right" allowDecimals={false} domain={[0, 'auto']} stroke="#087f8c" width={55} tickFormatter={number} />
                <Tooltip labelFormatter={date} formatter={(value, name) => [number(value), name]} />
                <Line yAxisId="calls" dataKey="provider_calls" name={t('Provider calls')} stroke="#4944a8" strokeWidth={2} dot={{ r: 3 }} isAnimationActive={false} />
                <Line yAxisId="tokens" dataKey="total_tokens" name={t('Total tokens')} stroke="#087f8c" strokeDasharray="5 3" strokeWidth={2} dot={{ r: 3 }} isAnimationActive={false} />
              </LineChart>
            </ResponsiveContainer>
          </div> : <p className="chart-empty" role="status">{t('No provider activity in the last 7 UTC days.')}</p>}
          <table className="sr-only"><caption>{t('AI usage — last 7 UTC days')}</caption>
            <thead><tr><th scope="col">{t('UTC date')}</th><th scope="col">{t('Provider calls')}</th><th scope="col">{t('Total tokens')}</th></tr></thead>
            <tbody>{trend.map(day => <tr key={day.date}><th scope="row">{day.date}</th><td>{number(day.provider_calls)}</td><td>{number(day.total_tokens)}</td></tr>)}</tbody>
          </table>
        </section>
        <section className="panel chart-panel" aria-labelledby="breakdown-heading">
          <h3 id="breakdown-heading">{t('Operations breakdown')}</h3>
          <p className="muted">{t('Provider calls by operation (all time)')}</p>
          {hasOperations ? <div className="chart-frame">
            <ResponsiveContainer width="100%" height="100%" minWidth={0} initialDimension={{ width: 480, height: 280 }}>
              <BarChart data={breakdown} layout="vertical" accessibilityLayer margin={{ top: 12, right: 20, bottom: 8, left: 0 }}>
                <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="#e0e5ed" />
                <XAxis type="number" allowDecimals={false} domain={[0, 'auto']} tickFormatter={number} />
                <YAxis type="category" dataKey="name" width={125} tick={{ fontSize: 12 }} />
                <Tooltip formatter={value => [number(value), t('Provider calls')]} />
                <Bar dataKey="provider_calls" name={t('Provider calls')} fill="#4944a8" radius={[0, 5, 5, 0]} maxBarSize={32} isAnimationActive={false} />
              </BarChart>
            </ResponsiveContainer>
          </div> : <p className="chart-empty" role="status">{t('No provider calls yet.')}</p>}
        </section>
      </div>
    </section>
  )
}
