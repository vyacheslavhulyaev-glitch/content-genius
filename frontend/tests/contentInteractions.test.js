import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { createServer } from 'vite'

// Exercise the real component handlers and asynchronous requests without a DOM dependency.
// Browser layout and native focus behavior remain part of the manual browser checks.
let server
let ContentList
let ContentCard
let hooks
const originalFetch = globalThis.fetch
const originalWindow = globalThis.window
const originalDocument = globalThis.document
let requests
let allRequests
let apiContents
let scrollCalls

before(async () => {
  server = await createServer({
    server: { middlewareMode: true, hmr: false, ws: false }, appType: 'custom',
    plugins: [{
      name: 'interaction-test-hooks', enforce: 'pre',
      resolveId(id) { if (id === 'test-hooks') return '\0test-hooks' },
      load(id) {
        if (id !== '\0test-hooks') return
        return `
          let slots = [], cursor = 0, effects = [];
          export function reset() { slots = []; cursor = 0; effects = []; }
          export function render(component, props) { cursor = 0; return component(props); }
          export function renderIsolated(component, props) {
            const saved = [slots, cursor, effects];
            slots = []; cursor = 0; effects = [];
            try { return component(props); }
            finally { [slots, cursor, effects] = saved; }
          }
          export function flushEffects() { const pending = effects; effects = []; pending.forEach(fn => fn()); }
          export function useState(initial) {
            const index = cursor++;
            if (!(index in slots)) slots[index] = typeof initial === 'function' ? initial() : initial;
            return [slots[index], value => { slots[index] = typeof value === 'function' ? value(slots[index]) : value; }];
          }
          export function useRef(initial) { return useState(() => ({ current: initial }))[0]; }
          export function useEffect(effect, deps) {
            const index = cursor++;
            if (!slots[index] || deps.some((value, i) => value !== slots[index][i])) effects.push(effect);
            slots[index] = deps;
          }
          export function useTranslation() { return { t: key => key, i18n: { resolvedLanguage: 'en' } }; }
        `
      },
      transform(code, id) {
        if (!/\/components\/Content(List|Card)\.jsx$/.test(id)) return
        return code.replace(/from 'react'/g, "from 'test-hooks'")
          .replace(/from 'react-i18next'/g, "from 'test-hooks'")
      },
    }],
  })
  hooks = await server.ssrLoadModule('test-hooks')
  ContentList = (await server.ssrLoadModule('/src/components/ContentList.jsx')).default
  ContentCard = (await server.ssrLoadModule('/src/components/ContentCard.jsx')).default
})

after(async () => {
  await server?.close()
  globalThis.fetch = originalFetch
  globalThis.window = originalWindow
  globalThis.document = originalDocument
})

const versions = [
  { id: 101, content_language: 'en', has_generated_content: true, is_generation_stale: false },
  { id: 118, content_language: 'de', has_generated_content: false, is_generation_stale: false },
  { id: 133, content_language: 'uk', has_generated_content: false, is_generation_stale: false },
]
const fixtures = () => versions.map(version => ({
  ...version, content_group_id: 42, primary_language: 'en', title: `Title ${version.id}`,
  topic: 'User topic', generated_content: version.has_generated_content ? 'User generated text' : null,
  created_at: '2026-09-30T12:00:00Z', translations: versions,
}))
const response = (body, status = 200) => ({ ok: status < 400, status, json: async () => body })
const tick = () => new Promise(resolve => setTimeout(resolve, 0))
const onSessionExpired = () => assert.fail('Unexpected expired session')
const renderList = () => hooks.render(ContentList, { onSessionExpired, refreshVersion: 0 })
function nodes(tree, predicate) {
  if (!tree || typeof tree !== 'object') return []
  if (Array.isArray(tree)) return tree.flatMap(child => nodes(child, predicate))
  return [...(predicate(tree) ? [tree] : []), ...nodes(tree.props?.children, predicate)]
}
const cards = () => nodes(renderList(), node => node.type === ContentCard)
const card = (group = 42) => cards().find(node => node.props.content.content_group_id === group).props

async function setup(contents = fixtures()) {
  hooks.reset()
  scrollCalls = []
  globalThis.window = { location: { hash: '#unchanged' }, scrollY: 640,
    scrollTo: (...args) => scrollCalls.push(args), scrollBy: (...args) => scrollCalls.push(args) }
  globalThis.document = { cookie: 'XSRF-TOKEN=test',
    getElementById: () => ({ scrollIntoView: (...args) => scrollCalls.push(args), focus: (...args) => scrollCalls.push(args) }) }
  requests = []
  allRequests = []
  apiContents = structuredClone(contents)
  globalThis.fetch = (url, options) => {
    allRequests.push({ url, options })
    if (!options.method) return Promise.resolve(response(structuredClone(apiContents)))
    return new Promise(resolve => requests.push({ url, options, resolve }))
  }
  renderList()
  hooks.flushEffects()
  await tick()
}

function languageButtons() {
  const tree = hooks.renderIsolated(ContentCard, card())
  return nodes(nodes(tree, node => node.type === 'nav')[0], node => node.type === 'button')
}

function assertLanguageStates(expected) {
  assert.deepEqual(Object.fromEntries(languageButtons().map(button => [
    button.props.children[0], button.props.children[2].props.children,
  ])), Object.fromEntries(expected.map(({ language, icon }) => [language, icon])))
  const active = languageButtons().filter(button => button.props['aria-current'] === 'true')
  assert.equal(active.length, 1)
  assert.equal(active[0].props['data-content-id'], card().content.id)
}

async function switchRepeatedly(expected) {
  const before = structuredClone(card().content.translations)
  const requestCount = allRequests.length
  for (let cycle = 0; cycle < 4; cycle++) {
    for (const id of [118, 133, 101]) {
      languageButtons().find(button => button.props['data-content-id'] === id).props.onClick()
      assert.equal(card().content.id, id)
      assertLanguageStates(expected)
      renderList()
      hooks.flushEffects()
      await tick()
      assert.deepEqual(card().content.translations, before)
      assert.equal(allRequests.length, requestCount, 'Selecting a language must not make any server request')
    }
  }
}

const draftStates = [
  { language: 'EN', icon: '✓' }, { language: 'DE', icon: '○' }, { language: 'UA', icon: '○' },
]

test('repeated language button clicks preserve each version status and never request the server', async () => {
  await setup()
  assertLanguageStates(draftStates)
  await switchRepeatedly(draftStates)
  assert.equal(requests.length, 0)
  assert.equal(card().content.generated_content, 'User generated text')
})

test('creating drafts, switching, rehydrating, generating and editing keep per-Content status', async () => {
  const original = fixtures()[0]
  await setup([{ ...original, translations: versions.slice(0, 1) }])
  assertLanguageStates([
    { language: 'EN', icon: '✓' }, { language: 'DE', icon: '+' }, { language: 'UA', icon: '+' },
  ])
  for (const [language, id] of [['de', 118], ['uk', 133]]) {
    const label = language === 'uk' ? 'UA' : 'DE'
    const pending = languageButtons().find(button => button.props.children[0] === label).props.onClick()
    const created = fixtures().find(content => content.id === id)
    const translations = versions.filter(version => version.id <= id)
    created.translations = translations
    apiContents = [...apiContents.map(content => ({ ...content, translations })), created]
    const request = requests.at(-1)
    assert.match(request.url, /\/translations$/)
    assert.equal(request.options.method, 'POST')
    assert.deepEqual(JSON.parse(request.options.body), { content_language: language })
    request.resolve(response(structuredClone(created), 201))
    assert.equal(await pending, true)
    assert.equal(card().content.id, id)
    assert.equal(card().content.generated_content, null)
    assert.equal(languageButtons().find(button => button.props['data-content-id'] === id).props.children[2].props.children, '○')
    renderList()
    hooks.flushEffects()
    await tick()
  }
  assert.equal(requests.length, 2, 'Only the two explicit draft creation requests are allowed')
  await switchRepeatedly(draftStates)

  // A fresh mount consumes a new API snapshot, just as a page reload does.
  hooks.reset()
  renderList()
  hooks.flushEffects()
  await tick()
  assertLanguageStates(draftStates)
  await switchRepeatedly(draftStates)
  assert.equal(requests.length, 2)

  languageButtons().find(button => button.props['data-content-id'] === 118).props.onClick()
  const generation = card().onGenerate()
  const generating = [...draftStates]
  generating[1] = { language: 'DE', icon: '…' }
  assertLanguageStates(generating)
  assert.match(requests[2].url, /\/118\/generate$/)
  const generated = { ...card().content, generated_content: 'Generated German text',
    translations: versions.map(version => version.id === 118 ? { ...version, has_generated_content: true } : version) }
  requests[2].resolve(response({ content: generated }))
  assert.equal(await generation, true)
  const current = [...draftStates]
  current[1] = { language: 'DE', icon: '✓' }
  await switchRepeatedly(current)

  languageButtons().find(button => button.props['data-content-id'] === 118).props.onClick()
  const editing = card().onSave({ topic: 'Changed German topic' })
  assert.match(requests[3].url, /\/118$/)
  assert.equal(requests[3].options.method, 'PATCH')
  const edited = { ...generated, topic: 'Changed German topic', is_generation_stale: true,
    translations: generated.translations.map(version => version.id === 118 ? { ...version, is_generation_stale: true } : version) }
  apiContents = apiContents.map(content => content.id === 118 ? edited : { ...content, translations: edited.translations })
  requests[3].resolve(response(edited))
  assert.equal(await editing, true)
  renderList()
  hooks.flushEffects()
  await tick()
  const stale = [...draftStates]
  stale[1] = { language: 'DE', icon: '↻' }
  await switchRepeatedly(stale)
  assert.equal(requests.length, 4)
})

test('existing version selection opens each exact Content ID in the same group slot', async () => {
  await setup()
  assert.equal(cards().length, 1)
  for (const id of [118, 133, 101]) {
    card().onSelectVersion(id)
    assert.equal(card().content.id, id)
    assert.equal(nodes(renderList(), node => node.type === 'li')[0].key, '42')
  }
  assert.equal(window.location.hash, '#unchanged')
  assert.equal(window.scrollY, 640)
  assert.deepEqual(scrollCalls, [])
})

test('language controls are buttons whose clicks select IDs or create the missing language', () => {
  hooks.reset()
  const selected = []
  const added = []
  const tree = hooks.render(ContentCard, {
    content: { ...fixtures()[0], translations: versions.slice(0, 2) },
    onSelectVersion: id => selected.push(id), onAddLanguage: language => added.push(language),
  })
  const nav = nodes(tree, node => node.type === 'nav')[0]
  assert.equal(nodes(nav, node => node.type === 'a' || node.props?.href).length, 0)
  const buttons = nodes(nav, node => node.type === 'button')
  for (const button of buttons) button.props.onClick()
  assert.deepEqual(selected, [101, 118])
  assert.deepEqual(added, ['uk'])
  assert.equal(buttons[0].props['aria-current'], 'true')
  assert.equal(buttons[1].props['aria-current'], undefined)
  assert.equal(window.location.hash, '#unchanged')
  assert.deepEqual(scrollCalls, [])
})

for (const previous of ['draft', 'generated', 'stale']) {
  for (const success of [true, false]) {
    test(`${previous} DE generation ${success ? 'success' : 'failure'} settles only its Content ID`, async () => {
      const contents = fixtures()
      contents[1] = { ...contents[1], generated_content: previous === 'draft' ? null : 'Previous German output',
        is_generation_stale: previous === 'stale' }
      const translations = versions.map(version => version.id === 118
        ? { ...version, has_generated_content: previous !== 'draft', is_generation_stale: previous === 'stale' } : version)
      contents.forEach(content => { content.translations = translations })
      await setup(contents)
      card().onSelectVersion(118)
      const pending = card().onGenerate()
      assert.match(requests[0].url, new RegExp(`/118/${previous === 'draft' ? 'generate' : 'regenerate'}$`))
      assert.deepEqual([...card().generatingContentIds], [118])
      card().onSelectVersion(101)
      assert.equal(card().isGenerating, false)
      assert.equal(card().groupBusy, true)
      const updated = { ...contents[1], generated_content: 'New German output', is_generation_stale: false,
        translations: translations.map(version => version.id === 118
          ? { ...version, has_generated_content: true, is_generation_stale: false } : version) }
      requests[0].resolve(response(success ? { content: updated } : {}, success ? 200 : 503))
      assert.equal(await pending, success)
      assert.deepEqual([...card().generatingContentIds], [])
      assert.equal(card().content.id, 101)
      assert.deepEqual(card().content.translations.filter(version => version.id !== 118), [versions[0], versions[2]])
      card().onSelectVersion(118)
      assert.equal(card().content.generated_content, success ? 'New German output' : contents[1].generated_content)
      assert.equal(card().content.is_generation_stale, success ? false : previous === 'stale')
      assert.equal(Boolean(card().action.error), !success)
    })
  }
}

test('concurrent requests in separate groups preserve the other pending ID on failure', async () => {
  await setup([...fixtures(), { ...fixtures()[0], id: 200, content_group_id: 99 }])
  card().onSelectVersion(118)
  const first = card().onGenerate()
  const second = card(99).onGenerate()
  assert.deepEqual([...card().generatingContentIds], [118, 200])
  requests[0].resolve(response({}, 503))
  await first
  assert.deepEqual([...card().generatingContentIds], [200])
  requests[1].resolve(response({}, 503))
  await second
  assert.deepEqual([...card().generatingContentIds], [])
})

test('creating a missing version selects it without moving the group or navigating', async () => {
  const contents = fixtures().slice(0, 2).map(content => ({ ...content, translations: versions.slice(0, 2) }))
  await setup([...contents, { ...fixtures()[0], id: 200, content_group_id: 99 }])
  const beforeOrder = cards().map(node => node.props.content.content_group_id)
  const pending = card().onAddLanguage('uk')
  assert.match(requests[0].url, /101\/translations$/)
  assert.deepEqual(JSON.parse(requests[0].options.body), { content_language: 'uk' })
  requests[0].resolve(response(fixtures()[2], 201))
  assert.equal(await pending, true)
  assert.equal(card().content.id, 133)
  assert.deepEqual(cards().map(node => node.props.content.content_group_id), beforeOrder)
  assert.equal(card().content.generated_content, null)
  assert.equal(window.location.hash, '#unchanged')
  assert.equal(window.scrollY, 640)
  assert.deepEqual(scrollCalls, [])
})
