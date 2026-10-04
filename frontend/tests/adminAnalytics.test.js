import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { createServer } from 'vite'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'

let server, hooks, AdminPage, AiUsagePanel, i18n
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
        if (id.endsWith('/src/pages/AdminPage.jsx')) return code.replace(/from 'react'/g, "from 'admin-test-hooks'")
          .replace(/from 'react-i18next'/g, "from 'admin-test-hooks'")
      },
    }],
  })
  i18n = (await server.ssrLoadModule('/src/lib/i18n.js')).default
  hooks = await server.ssrLoadModule('admin-test-hooks')
  AdminPage = (await server.ssrLoadModule('/src/pages/AdminPage.jsx')).default
  AiUsagePanel = (await server.ssrLoadModule('/src/components/AiUsagePanel.jsx')).default
})

after(async () => { await server?.close(); globalThis.fetch = previousFetch; globalThis.document = previousDocument })

const values = { provider_calls: 3, completed_provider_calls: 2, failed_provider_calls: 1, pending_provider_calls: 0,
  input_tokens: 123, output_tokens: 45, total_tokens: 168, estimated_cost: '0.00012345', unknown_usage_calls: 1, unknown_cost_calls: 1,
  logical_requests: { total: 1, completed: 0, failed: 1, pending: 0 } }
const usage = { currency: 'USD', timezone: 'UTC', periods: { today: values, month: values, all_time: values },
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
      'Output tokens', 'Total tokens', 'Generation', 'Input moderation', 'Output moderation', 'Calls with unknown usage']) {
      assert.ok(html.includes(i18n.t(label)), label)
    }
    assert.ok(html.includes(Number(values.estimated_cost).toLocaleString(language, {
      style: 'currency', currency: 'USD', minimumFractionDigits: 6, maximumFractionDigits: 8,
    })))
    assert.ok(html.includes('168'))
    assert.ok(html.includes('UTC'))
    assert.ok(html.includes('—'))
    assert.doesNotMatch(html, /\{\{currency\}\}|NaN|undefined/)
  }
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
