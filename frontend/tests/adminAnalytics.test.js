import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { createServer } from 'vite'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'

let server, hooks, AdminPage, AiUsagePanel, AiUsageCharts, i18n
const previousFetch = globalThis.fetch
const previousDocument = globalThis.document

before(async () => {
  globalThis.document = { documentElement: { lang: '' } }
  server = await createServer({ server: { middlewareMode: true, hmr: false, ws: false }, appType: 'custom',
    plugins: [{
      name: 'admin-analytics-test-hooks', enforce: 'pre',
      resolveId(id) { if (id === 'admin-test-hooks') return '\0admin-test-hooks' },
      load(id) {
        if (id !== '\0admin-test-hooks') return
        return `
          import i18n from '/src/lib/i18n.js';
          let slots = [], cursor = 0, effects = [];
          export function reset() { slots = []; cursor = 0; effects = []; }
          export function render(component, props) { cursor = 0; return component(props); }
          export function flushEffects() { const pending = effects; effects = []; pending.forEach(effect => effect()); }
          export function useState(initial) {
            const index = cursor++;
            if (!(index in slots)) slots[index] = initial;
            return [slots[index], value => { slots[index] = value; }];
          }
          export function useEffect(effect, deps) {
            const index = cursor++;
            if (!slots[index] || deps.some((value, i) => value !== slots[index][i])) effects.push(effect);
            slots[index] = deps;
          }
          export function useTranslation() { return { t: i18n.t.bind(i18n), i18n }; }
        `
      },
      transform(code, id) {
        if (/\/src\/(pages\/AdminPage|components\/AiUsageCharts)\.jsx$/.test(id)) return code.replace(/from 'react'/g, "from 'admin-test-hooks'")
          .replace(/from 'react-i18next'/g, "from 'admin-test-hooks'")
      },
    }],
  })
  i18n = (await server.ssrLoadModule('/src/lib/i18n.js')).default
  hooks = await server.ssrLoadModule('admin-test-hooks')
  AdminPage = (await server.ssrLoadModule('/src/pages/AdminPage.jsx')).default
  AiUsagePanel = (await server.ssrLoadModule('/src/components/AiUsagePanel.jsx')).default
  AiUsageCharts = (await server.ssrLoadModule('/src/components/AiUsageCharts.jsx')).default
})

after(async () => { await server?.close(); globalThis.fetch = previousFetch; globalThis.document = previousDocument })

const values = { provider_calls: 3, completed_provider_calls: 2, failed_provider_calls: 1, pending_provider_calls: 0,
  input_tokens: 123, output_tokens: 45, total_tokens: 168, estimated_cost: '0.00012345', unknown_usage_calls: 1, unknown_cost_calls: 1,
  logical_requests: { total: 1, completed: 0, failed: 1, pending: 0 } }
const usage = { currency: 'USD', timezone: 'UTC', periods: { today: values, month: values, all_time: values },
  trend_7_days: Array.from({ length: 7 }, (_, index) => ({ date: `2026-10-${String(index + 1).padStart(2, '0')}`, provider_calls: index === 6 ? 3 : 0, total_tokens: index === 6 ? 168 : 0 })),
  operations: { generation: values, input_moderation: values, output_moderation: { ...values, estimated_cost: null } } }
const onSessionExpired = () => assert.fail('Unexpected expired session')
const props = { onSessionExpired }
const markup = tree => renderToStaticMarkup(tree)
const tick = () => new Promise(resolve => setTimeout(resolve, 0))

test('admin endpoint response renders period and operation analytics with localized cost and unknown usage', async () => {
  for (const language of ['en', 'uk', 'de']) {
    await i18n.changeLanguage(language)
    hooks.reset()
    const calls = []
    globalThis.fetch = async url => {
      calls.push(url)
      return { ok: true, status: 200, json: async () => ({
        summary: { total_users: 1, total_contents: 2, generated_contents: 1, total_ai_requests: 1,
          completed_ai_requests: 0, failed_ai_requests: 1, pending_ai_requests: 0 },
        provider_usage: usage, recent_ai_requests: [],
      }) }
    }
    hooks.render(AdminPage, props)
    hooks.flushEffects()
    await tick()
    const html = markup(hooks.render(AdminPage, props))
    assert.equal(calls.length, 1)
    assert.ok(calls[0].endsWith('/api/admin/dashboard'))
    for (const label of ['AI usage and estimated cost', 'Today', 'Current month', 'All time', 'Input tokens',
      'Output tokens', 'Total tokens', 'Generation', 'Input moderation', 'Output moderation', 'Calls with unknown usage',
      'Activity analytics', 'AI usage — last 7 UTC days', 'Operations breakdown', 'Left axis', 'Right axis']) {
      assert.ok(html.includes(i18n.t(label)), label)
    }
    assert.ok(html.includes(Number(values.estimated_cost).toLocaleString(language, {
      style: 'currency', currency: 'USD', minimumFractionDigits: 6, maximumFractionDigits: 8,
    })))
    assert.ok(html.includes('168'))
    assert.ok(html.includes('UTC'))
    assert.equal((html.match(/class="recharts-responsive-container"/g) ?? []).length, 2)
    assert.ok(html.includes('2026-10-07'))
    assert.ok(html.includes('—'))
    assert.doesNotMatch(html, /\{\{currency\}\}|NaN|undefined/)
  }
})

test('recruiter dashboard shows read-only notice and sanitized recent requests in EN UK DE', async () => {
  for (const language of ['en', 'uk', 'de']) {
    await i18n.changeLanguage(language)
    hooks.reset()
    globalThis.fetch = async () => ({ ok: true, status: 200, json: async () => ({
      summary: { total_users: 2, total_contents: 1, generated_contents: 1, total_ai_requests: 1,
        completed_ai_requests: 1, failed_ai_requests: 0, pending_ai_requests: 0 },
      provider_usage: usage, recent_ai_requests: [{ id: 12, user: { id: 8, name: 'User #8' },
        content: { id: 9, title: 'Content #9' }, status: 'completed', tokens_used: 168, created_at: '2026-10-07T12:00:00Z' }],
    }) })
    const demoProps = { ...props, isAdminDemo: true }
    hooks.render(AdminPage, demoProps)
    hooks.flushEffects()
    await tick()
    const html = markup(hooks.render(AdminPage, demoProps))
    assert.ok(html.includes(i18n.t('Read-only recruiter demo')))
    assert.ok(html.includes(i18n.t('Explore real activity analytics. User identities and content titles are hidden. Content changes and AI generation are disabled.')))
    assert.match(html, /User #8/)
    assert.match(html, /Content #9/)
    assert.doesNotMatch(html, /table-email|undefined|NaN/)
  }
})

test('zero and absent chart analytics show sensible localized empty states', async () => {
  for (const language of ['en', 'uk', 'de']) {
    await i18n.changeLanguage(language)
    for (const zeroUsage of [{}, { trend_7_days: usage.trend_7_days.map(day => ({ ...day, provider_calls: 0, total_tokens: 0 })),
      operations: Object.fromEntries(Object.keys(usage.operations).map(operation => [operation, { provider_calls: 0 }])) }]) {
      const html = markup(React.createElement(AiUsageCharts, { usage: zeroUsage }))
      assert.ok(html.includes(i18n.t('No provider activity in the last 7 UTC days.')))
      assert.ok(html.includes(i18n.t('No provider calls yet.')))
      assert.doesNotMatch(html, /NaN|undefined|recharts-surface/)
    }
  }
})

test('chart series use returned data, separate call/token axes, and all three operation counts', () => {
  function nodes(tree, predicate) {
    if (!tree || typeof tree !== 'object') return []
    if (Array.isArray(tree)) return tree.flatMap(child => nodes(child, predicate))
    return [...(predicate(tree) ? [tree] : []), ...nodes(tree.props?.children, predicate)]
  }
  const tree = AiUsageCharts({ usage })
  const charts = nodes(tree, node => node.props?.accessibilityLayer === true)
  assert.equal(charts.length, 2)
  assert.deepEqual(charts[0].props.data, usage.trend_7_days)
  assert.deepEqual(charts[1].props.data.map(item => item.provider_calls), [3, 3, 3])
  const series = nodes(tree, node => node.props?.dataKey && node.props?.strokeWidth === 2)
  assert.deepEqual(series.map(line => [line.props.dataKey, line.props.yAxisId]), [['provider_calls', 'calls'], ['total_tokens', 'tokens']])
})

test('unknown costs render a dash while known zero costs render an explicit currency amount', async () => {
  await i18n.changeLanguage('en')
  const html = markup(React.createElement(AiUsagePanel, { usage: { ...usage,
    periods: { today: { ...values, estimated_cost: null }, month: { ...values, estimated_cost: '0.00000000' }, all_time: values },
  } }))
  assert.match(html, /<dd>—<\/dd>/)
  assert.match(html, /\$0\.000000/)
})

test('forbidden admin responses show a localized error without rendering provider analytics', async () => {
  await i18n.changeLanguage('uk')
  hooks.reset()
  globalThis.fetch = async () => ({ ok: false, status: 403, json: async () => ({ message: 'Forbidden' }) })
  hooks.render(AdminPage, props)
  hooks.flushEffects()
  await tick()
  const html = markup(hooks.render(AdminPage, props))
  assert.ok(html.includes(i18n.t('Access denied. You do not have permission to view this page.')))
  assert.doesNotMatch(html, /provider-usage-heading|operation-heading/)
})
