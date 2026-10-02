import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { readFile } from 'node:fs/promises'
import { createServer } from 'vite'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'

let server
let i18n
let ContentCard
let DraftForm
let LoginForm
let ContentLanguageSelect
const storage = new Map([['contentgenius.ui-language', 'uk']])
const previousDocument = globalThis.document
const previousStorage = Object.getOwnPropertyDescriptor(globalThis, 'localStorage')

before(async () => {
  globalThis.document = { documentElement: { lang: '' } }
  Object.defineProperty(globalThis, 'localStorage', { configurable: true, value: {
    getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value),
  } })
  server = await createServer({ server: { middlewareMode: true, hmr: false, ws: false }, appType: 'custom' })
  i18n = (await server.ssrLoadModule('/src/lib/i18n.js')).default
  ContentCard = (await server.ssrLoadModule('/src/components/ContentCard.jsx')).default
  DraftForm = (await server.ssrLoadModule('/src/components/DraftForm.jsx')).default
  LoginForm = (await server.ssrLoadModule('/src/components/LoginForm.jsx')).default
  ContentLanguageSelect = (await server.ssrLoadModule('/src/components/ContentLanguageSelect.jsx')).default
})

after(async () => {
  await server?.close()
  globalThis.document = previousDocument
  if (previousStorage) Object.defineProperty(globalThis, 'localStorage', previousStorage)
  else delete globalThis.localStorage
})

const render = (component, props = {}) => renderToStaticMarkup(React.createElement(component, props))
const content = {
  id: 101, title: 'Original title', topic: 'Original topic', tone: 'Original tone', length: 'Short',
  content_language: 'en', primary_language: 'en', generated_content: 'Original generated text',
  created_at: '2026-09-30T12:00:00Z',
  translations: [
    { id: 101, content_language: 'en', has_generated_content: true, is_generation_stale: false },
    { id: 118, content_language: 'de', has_generated_content: false, is_generation_stale: false },
  ],
}

const versionButton = (markup, id) => markup.match(new RegExp(`<button[^>]*data-content-id="${id}"[^>]*>.*?</button>`))?.[0]

test('stored UI locale does not select a different article version or draft language', async () => {
  assert.equal(i18n.resolvedLanguage, 'uk')
  for (const language of ['uk', 'de', 'en']) {
    await i18n.changeLanguage(language)
    assert.equal(globalThis.document.documentElement.lang, language)
    assert.equal(storage.get('contentgenius.ui-language'), language)
    const card = render(ContentCard, { content, generatingContentIds: new Set([118]) })
    assert.match(card, /data-content-id="101" aria-current="true"/)
    assert.match(card, /data-content-id="118"/)
    assert.match(card, /Original generated text/)
    assert.match(versionButton(card, 101), /✓/)
    assert.match(versionButton(card, 118), /…/)
    assert.doesNotMatch(versionButton(card, 118), /✓/)
    assert.match(render(DraftForm), /value="en"[^>]*selected=""/)
  }
  assert.equal(content.content_language, 'en')
  assert.equal(content.primary_language, 'en')
})

test('version buttons and missing-language actions are localized independently of stored text', async () => {
  await i18n.changeLanguage('en')
  const card = render(ContentCard, { content })
  assert.match(card, /aria-label="Open Deutsch version: Language version exists, content not generated yet"/)
  assert.match(card, /aria-label="Add Українська version: No language version yet"/)
  assert.doesNotMatch(card, /aria-label="Add English version:/)
  assert.doesNotMatch(card, /aria-label="Add Deutsch version:/)
  assert.match(card, /title="Add Українська version: No language version yet"[^>]*>UA <span aria-hidden="true">\+<\/span>/)
  const pending = render(ContentCard, { content, action: { pending: 'translations' } })
  assert.match(pending, /Adding language version/)
  assert.match(pending, /disabled="" aria-label="Add Українська version: No language version yet"/)
})

test('opening a draft highlights its button independently and keeps the neutral status icon', async () => {
  await i18n.changeLanguage('en')
  const draft = { ...content, id: 118, content_language: 'de', generated_content: null }
  const card = render(ContentCard, { content: draft })
  const selected = versionButton(card, 118)
  assert.match(selected, /aria-current="true"/)
  assert.match(selected, /○/)
  assert.doesNotMatch(selected, /✓/)
  assert.match(selected, /title="Open Deutsch version: Language version exists, content not generated yet"/)
  assert.doesNotMatch(versionButton(card, 101), /aria-current/)
  assert.match(versionButton(card, 101), /✓/)
})

test('generated and stale versions have distinct icons and accessible status labels', async () => {
  await i18n.changeLanguage('en')
  const stale = { ...content, translations: [content.translations[0], {
    ...content.translations[1], has_generated_content: true, is_generation_stale: true,
  }] }
  const card = render(ContentCard, { content: stale })
  assert.match(versionButton(card, 101), /✓/)
  assert.match(versionButton(card, 101), /title="Open English version: Generated and up to date"/)
  assert.match(versionButton(card, 118), /↻/)
  assert.match(versionButton(card, 118), /aria-label="Open Deutsch version: Needs regeneration"/)
  assert.doesNotMatch(versionButton(card, 118), /✓/)
})

test('pending generation is shown on the current and sibling cards without a completion checkmark', async () => {
  await i18n.changeLanguage('en')
  const current = render(ContentCard, { content, action: { pending: 'regenerate' }, isGenerating: true, generatingContentIds: new Set([101]) })
  assert.match(versionButton(current, 101), /…/)
  assert.match(versionButton(current, 101), /Generation in progress/)
  assert.doesNotMatch(versionButton(current, 101), /✓/)
  const sibling = render(ContentCard, { content, generatingContentIds: new Set([118]) })
  assert.match(versionButton(sibling, 118), /…/)
  assert.match(versionButton(sibling, 118), /Generation in progress/)
  assert.match(versionButton(sibling, 101), /✓/)
})

test('generating or regenerating DE leaves EN and UK unchanged on every card, regardless of active language', async () => {
  await i18n.changeLanguage('en')
  for (const operation of ['generate', 'regenerate']) {
    const translations = [content.translations[0], {
      ...content.translations[1], has_generated_content: operation === 'regenerate',
    }, { id: 133, content_language: 'uk', has_generated_content: false, is_generation_stale: false }]
    const generatingContentIds = new Set([118])
    for (const version of translations) {
      const ownContent = { ...content, id: version.id, content_language: version.content_language,
        generated_content: version.has_generated_content ? 'Existing text' : null, translations }
      const ownPending = generatingContentIds.has(version.id)
      const card = render(ContentCard, {
        content: ownContent, groupBusy: true, generatingContentIds, isGenerating: ownPending,
        action: ownPending ? { pending: operation } : undefined,
      })
      assert.match(versionButton(card, 101), /✓/)
      assert.doesNotMatch(versionButton(card, 101), /…/)
      assert.match(versionButton(card, 118), /…/)
      assert.match(versionButton(card, 133), /○/)
      assert.doesNotMatch(versionButton(card, 133), /…/)
      assert.match(versionButton(card, version.id), /aria-current="true"/)
      assert.equal(card.includes('role="status"'), ownPending)
      assert.equal(card.includes(operation === 'generate' ? '>Generating...</button>' : '>Regenerating...</button>'), ownPending)
    }
  }
})

test('successful DE generation becomes current while failure restores draft or stale status', async () => {
  await i18n.changeLanguage('en')
  for (const previous of ['draft', 'generated', 'stale']) {
    const translations = [content.translations[0], {
      ...content.translations[1], has_generated_content: previous !== 'draft', is_generation_stale: previous === 'stale',
    }, { id: 133, content_language: 'uk', has_generated_content: false, is_generation_stale: false }]
    const original = { ...content, translations }
    const pending = render(ContentCard, { content: original, generatingContentIds: new Set([118]) })
    assert.match(versionButton(pending, 118), /…/)

    const failed = render(ContentCard, { content: original, generatingContentIds: new Set(), groupBusy: false })
    assert.ok(versionButton(failed, 118).includes(previous === 'draft' ? '○' : previous === 'stale' ? '↻' : '✓'))
    assert.match(versionButton(failed, 101), /✓/)
    assert.match(versionButton(failed, 133), /○/)

    const completed = { ...original, translations: translations.map(version => version.id === 118
      ? { ...version, has_generated_content: true, is_generation_stale: false } : version) }
    const succeeded = render(ContentCard, { content: completed, generatingContentIds: new Set() })
    assert.match(versionButton(succeeded, 118), /✓/)
    assert.match(versionButton(succeeded, 101), /✓/)
    assert.match(versionButton(succeeded, 133), /○/)
    assert.doesNotMatch(succeeded, /…/)
  }
})

test('two generating IDs are independent and settling one does not clear the other', async () => {
  await i18n.changeLanguage('uk')
  const original = { ...content, translations: [...content.translations,
    { id: 133, content_language: 'uk', has_generated_content: false, is_generation_stale: false }] }
  const generatingContentIds = new Set([118, 133])
  const both = render(ContentCard, { content: original, generatingContentIds })
  assert.match(versionButton(both, 101), /✓/)
  assert.match(versionButton(both, 101), /aria-current="true"/)
  assert.match(versionButton(both, 118), /…/)
  assert.match(versionButton(both, 133), /…/)

  const remaining = new Set(generatingContentIds)
  remaining.delete(118)
  const settled = render(ContentCard, { content: original, generatingContentIds: remaining })
  assert.match(versionButton(settled, 101), /✓/)
  assert.match(versionButton(settled, 118), /○/)
  assert.match(versionButton(settled, 133), /…/)
  assert.deepEqual([...generatingContentIds], [118, 133])
})

test('browser translation is disabled on article text and inputs, not UI containers', () => {
  const card = render(ContentCard, { content })
  assert.match(card, /<h3 translate="no" class="notranslate">Original title/)
  assert.match(card, /class="content-topic notranslate" translate="no">Original topic/)
  assert.match(card, /class="generated-content notranslate" translate="no" lang="en">Original generated text/)
  assert.doesNotMatch(card, /<article[^>]*translate="no"/)
  const draft = render(DraftForm)
  assert.equal((draft.match(/translate="no" class="notranslate"/g) ?? []).length, 8)
  assert.doesNotMatch(draft, /<section[^>]*translate="no"/)
})

test('occupied languages are disabled during editing and new drafts allow all languages', () => {
  const props = { id: 'language', value: 'en', onChange: () => {} }
  assert.match(render(ContentLanguageSelect, { ...props, disabledLanguages: ['de'] }), /value="de"[^>]*disabled=""/)
  assert.doesNotMatch(render(ContentLanguageSelect, props), /disabled=""/)
})

test('all locales contain the same UI messages and existing status messages retranslate', async () => {
  const dictionaries = await Promise.all(['en', 'uk', 'de'].map(async language => JSON.parse(await readFile(`src/locales/${language}.json`, 'utf8'))))
  const keys = dictionary => Object.keys(dictionary).filter(key => !key.startsWith('items_')).sort()
  assert.deepEqual(keys(dictionaries[0]), keys(dictionaries[1]))
  assert.deepEqual(keys(dictionaries[0]), keys(dictionaries[2]))
  for (const [index, language] of ['en', 'uk', 'de'].entries()) {
    await i18n.changeLanguage(language)
    assert.ok(render(LoginForm, { status: 'Signing in...' }).includes(dictionaries[index]['Signing in...']))
  }
})

test('moderation errors retranslate in EN UA DE while preserving generated text', async () => {
  const { moderationErrorMessage } = await server.ssrLoadModule('/src/lib/api.js')
  for (const language of ['en', 'uk', 'de']) {
    const dictionary = JSON.parse(await readFile(`src/locales/${language}.json`, 'utf8'))
    await i18n.changeLanguage(language)
    for (const code of ['moderation_input_blocked', 'moderation_output_blocked', 'moderation_unavailable']) {
      const message = moderationErrorMessage(code)
      assert.ok(dictionary[message])
      const markup = render(ContentCard, { content, action: { error: message, pending: false } })
      assert.ok(markup.includes(dictionary[message]))
      assert.match(markup, /Original generated text/)
    }
  }
  assert.equal(moderationErrorMessage('unknown_provider_error'), null)
})

test('SEO controls and generated meta fields localize without rendering AI HTML', async () => {
  const article = { ...content, generated_title: 'Generated H1',
    generated_content: '# H1\n\n## H2\n\n<script>alert(1)</script>\n\n### H3\n\n[guide](javascript:alert(1))',
    generated_content_html: '<h1>H1</h1><h2>H2</h2><p>&lt;script&gt;alert(1)&lt;/script&gt;</p><h3>H3</h3><p><a>guide</a></p>',
    generated_meta_title: '<img src=x onerror=alert(1)>', generated_meta_description: 'Saved SEO description' }
  for (const language of ['en', 'uk', 'de']) {
    await i18n.changeLanguage(language)
    const dictionary = JSON.parse(await readFile(`src/locales/${language}.json`, 'utf8'))
    const markup = render(ContentCard, { content: article })
    assert.ok(markup.includes(dictionary['Meta title']))
    assert.ok(markup.includes(dictionary['Meta description']))
    assert.match(markup, /&lt;script&gt;/)
    assert.match(markup, /&lt;img/)
    assert.doesNotMatch(markup, /<script|<img|href="javascript:/)
    assert.match(markup, /Saved SEO description/)
    const form = render(DraftForm)
    for (const label of ['Primary keyword', 'Secondary keywords', 'Meta title guidance', 'Meta description guidance', 'Add link', 'Article length']) {
      assert.ok(form.includes(dictionary[label]))
    }
  }
})

test('normal article presentation renders backend Markdown headings, links, paragraphs and lists', () => {
  const markdown = '# Article H1\n\n## Section H2\n\nBody with [guide](https://example.com/guide).\n\n### Details H3\n\n- First item'
  const article = { ...content, generated_content: markdown,
    generated_content_html: '<h1>Article H1</h1><h2>Section H2</h2><p>Body with <a href="https://example.com/guide">guide</a>.</p><h3>Details H3</h3><ul><li>First item</li></ul>',
  }
  const markup = render(ContentCard, { content: article })
  for (const fragment of ['<h1>Article H1</h1>', '<h2>Section H2</h2>', '<h3>Details H3</h3>',
    '<a href="https://example.com/guide">guide</a>', '<p>Body with', '<ul><li>First item</li></ul>']) {
    assert.ok(markup.includes(fragment))
  }
  assert.doesNotMatch(markup, /# Article H1|## Section H2|### Details H3|\[guide\]\(/)
  assert.equal(article.generated_content, markdown)
})

test('raw provider HTML stays escaped when no server presentation field is available', () => {
  const article = { ...content, generated_content: '<script>alert(1)</script><img src=x onerror=alert(1)>' }
  const markup = render(ContentCard, { content: article })
  assert.match(markup, /&lt;script&gt;/)
  assert.doesNotMatch(markup, /<script|<img/)
})

test('quota and purpose errors render localized EN UK DE messages with retry values', async () => {
  const { generationErrorMessage } = await server.ssrLoadModule('/src/lib/api.js')
  for (const language of ['en', 'uk', 'de']) {
    await i18n.changeLanguage(language)
    for (const code of ['generation_rate_limited', 'generation_purpose_blocked']) {
      const message = generationErrorMessage(code)
      assert.ok(i18n.exists(message))
      const markup = render(ContentCard, { content, action: { error: message, errorValues: { minutes: 3 } } })
      assert.ok(markup.includes(i18n.t(message, { minutes: 3 })))
      assert.match(markup, /Original generated text/)
      assert.doesNotMatch(markup, /\{\{minutes\}\}/)
    }
  }
})
