import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { createServer } from 'vite'

let server, hooks, App, LoginForm, LanguageSwitcher, AppHeader, i18n
const storage = new Map()
const storageKey = 'contentgenius.ui-language'
const originalFetch = globalThis.fetch
const originalDocument = globalThis.document
const originalStorage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')

before(async () => {
  globalThis.document = { documentElement: { lang: '' }, cookie: 'XSRF-TOKEN=test' }
  Object.defineProperty(globalThis, 'localStorage', { configurable: true, value: {
    getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value),
  } })
  server = await createServer({
    server: { middlewareMode: true, hmr: false, ws: false }, appType: 'custom',
    plugins: [{
      name: 'public-language-test-hooks', enforce: 'pre',
      resolveId(id) { if (id === 'public-test-hooks') return '\0public-test-hooks' },
      load(id) {
        if (id !== '\0public-test-hooks') return
        return `
          import i18n from '/src/lib/i18n.js';
          export { lazy, Suspense } from 'react';
          let slots = [], cursor = 0, effects = [];
          export function reset() { slots = []; cursor = 0; effects = []; }
          export function render(component, props = {}) { cursor = 0; return component(props); }
          export function flushEffects() { const pending = effects; effects = []; pending.forEach(fn => fn()); }
          export function useState(initial) {
            const index = cursor++;
            if (!(index in slots)) slots[index] = typeof initial === 'function' ? initial() : initial;
            return [slots[index], value => { slots[index] = typeof value === 'function' ? value(slots[index]) : value; }];
          }
          export function useRef(initial) { return useState(() => ({ current: initial }))[0]; }
          export function useCallback(callback) { return callback; }
          export function useEffect(effect, deps) {
            const index = cursor++;
            if (!slots[index] || deps.some((value, i) => value !== slots[index][i])) effects.push(effect);
            slots[index] = deps;
          }
          export function useTranslation() { return { t: i18n.t.bind(i18n), i18n }; }
        `
      },
      transform(code, id) {
        if (!/\/src\/(App\.jsx|components\/(LoginForm|LanguageSwitcher|AppHeader)\.jsx)$/.test(id)) return
        return code.replace(/from 'react'/g, "from 'public-test-hooks'")
          .replace(/from 'react-i18next'/g, "from 'public-test-hooks'")
      },
    }],
  })
  i18n = (await server.ssrLoadModule('/src/lib/i18n.js')).default
  hooks = await server.ssrLoadModule('public-test-hooks')
  App = (await server.ssrLoadModule('/src/App.jsx')).default
  LoginForm = (await server.ssrLoadModule('/src/components/LoginForm.jsx')).default
  LanguageSwitcher = (await server.ssrLoadModule('/src/components/LanguageSwitcher.jsx')).default
  AppHeader = (await server.ssrLoadModule('/src/components/AppHeader.jsx')).default
})

after(async () => {
  await server?.close()
  globalThis.fetch = originalFetch
  globalThis.document = originalDocument
  if (originalStorage) Object.defineProperty(globalThis, 'localStorage', originalStorage)
  else delete globalThis.localStorage
})

function nodes(tree, predicate) {
  if (!tree || typeof tree !== 'object') return []
  if (Array.isArray(tree)) return tree.flatMap(child => nodes(child, predicate))
  return [...(predicate(tree) ? [tree] : []), ...nodes(tree.props?.children, predicate)]
}
const tick = () => new Promise(resolve => setTimeout(resolve, 0))
const renderApp = () => hooks.render(App)

test('public UI defaults to English with empty or unsupported localStorage', async () => {
  assert.equal(i18n.resolvedLanguage, 'en')
  storage.set(storageKey, 'fr')
  await server.ssrLoadModule('/src/lib/i18n.js?reload=unsupported')
  assert.equal(i18n.resolvedLanguage, 'en')
})

test('login language buttons use EN UK DE and locale survives real auth handlers and reload', async () => {
  for (const language of ['en', 'uk', 'de']) {
    hooks.reset()
    const loginTree = hooks.render(LoginForm, {})
    assert.equal(nodes(loginTree, node => node.type === LanguageSwitcher).length, 1)
    const switcher = LanguageSwitcher()
    const buttons = nodes(switcher, node => node.type === 'button')
    assert.deepEqual(buttons.map(button => button.props.lang), ['en', 'uk', 'de'])
    await buttons.find(button => button.props.lang === language).props.onClick()
    assert.equal(storage.get(storageKey), language)
    assert.equal(globalThis.document.documentElement.lang, language)
    assert.equal(nodes(LanguageSwitcher(), node => node.type === 'button' && node.props['aria-pressed']).length, 1)
    hooks.reset()
    let authenticated = false
    const requests = []
    globalThis.fetch = async (url, options) => {
      requests.push(url)
      if (url.endsWith('/login')) authenticated = true
      if (url.endsWith('/logout')) authenticated = false
      if (url.endsWith('/api/user')) return { ok: authenticated, status: authenticated ? 200 : 401,
        json: async () => ({ id: 1, name: 'Demo User', is_admin: false }) }
      assert.ok(options.method === 'POST' || url.endsWith('/sanctum/csrf-cookie'))
      return { ok: true, status: 204 }
    }
    renderApp()
    hooks.flushEffects()
    await tick()
    const login = renderApp()
    assert.equal(login.type, LoginForm)
    await login.props.onLogin('demo@contentgenius.example', 'test-only-password')
    assert.equal(i18n.resolvedLanguage, language)
    assert.equal(storage.get(storageKey), language)
    const header = nodes(renderApp(), node => node.type === AppHeader)[0]
    assert.equal(header.props.user.is_admin, false)
    await header.props.onLogout()
    assert.equal(renderApp().type, LoginForm)
    assert.equal(i18n.resolvedLanguage, language)
    assert.equal(storage.get(storageKey), language)
    await server.ssrLoadModule(`/src/lib/i18n.js?reload=${language}`)
    assert.equal(i18n.resolvedLanguage, language)
    assert.equal(globalThis.document.documentElement.lang, language)
    assert.equal(requests.filter(url => url.endsWith('/login')).length, 1)
    assert.equal(requests.filter(url => url.endsWith('/logout')).length, 1)
  }
})

test('recruiter login and restored session open only analytics; normal demo and real admin retain navigation', async () => {
  for (const restoreSession of [false, true]) {
    hooks.reset()
    let authenticated = restoreSession
    globalThis.fetch = async url => {
      if (url.endsWith('/login')) authenticated = true
      if (url.endsWith('/api/user')) return { ok: authenticated, status: authenticated ? 200 : 401,
        json: async () => ({ id: 9, name: 'Recruiter Demo', is_admin: false, is_admin_demo: true }) }
      return { ok: true, status: 204 }
    }
    renderApp()
    hooks.flushEffects()
    await tick()
    if (!restoreSession) await renderApp().props.onLogin('admin-demo@contentgenius.hideas.dev', 'test-only-password')
    const tree = renderApp()
    const header = nodes(tree, node => node.type === AppHeader)[0]
    assert.equal(header.props.page, 'admin')
    const demoNav = nodes(AppHeader(header.props), node => node.type === 'button' && node.props.className === 'nav-button')
    assert.equal(demoNav.length, 1)
    assert.equal(demoNav[0].props.children, i18n.t('Admin'))
    assert.equal(nodes(tree, node => node.type?.name === 'DashboardPage').length, 0)
    assert.equal(nodes(tree, node => node.props?.isAdminDemo === true).length, 1)
  }
  for (const [user, count] of [[{ is_admin: true }, 2], [{ is_demo: true, is_admin: false }, 1]]) {
    assert.equal(nodes(AppHeader({ user, page: 'dashboard' }), node => node.type === 'button' && node.props.className === 'nav-button').length, count)
  }
})
