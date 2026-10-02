import assert from 'node:assert/strict'
import { test } from 'node:test'
import { seoFormFields, seoPayload } from '../src/lib/seo.js'
import { validationMessage } from '../src/lib/validation.js'

test('SEO form values serialize keywords and contextual links independently of UI language', () => {
  const content = { title: 'Title', primary_keyword: ' casino ', secondary_keywords: ['slots', 'betting'],
    meta_title: ' Requested title ', meta_description: ' Description ',
    links: [{ anchor: ' guide ', url: ' https://example.com/guide ' }], content_language: 'de' }
  const fields = seoFormFields(content)
  assert.equal(fields.secondary_keywords, 'slots\nbetting')
  fields.secondary_keywords = ' slots \r\n\n betting '
  assert.deepEqual(seoPayload(fields), { primary_keyword: 'casino', secondary_keywords: ['slots', 'betting'],
    meta_title: 'Requested title', meta_description: 'Description',
    links: [{ anchor: 'guide', url: 'https://example.com/guide' }] })
  fields.links[0].anchor = 'changed'
  assert.equal(content.links[0].anchor, ' guide ')
  assert.equal(content.content_language, 'de')
})

test('empty optional SEO fields can be cleared and legacy drafts use title as keyword', () => {
  assert.deepEqual(seoPayload(seoFormFields()), { primary_keyword: null, secondary_keywords: [], meta_title: null, meta_description: null, links: [] })
  assert.equal(seoFormFields({ title: 'Existing title' }).primary_keyword, 'Existing title')
})

test('SEO validation failures map to localized messages', () => {
  const translated = key => `localized:${key}`
  for (const [error, expected] of [
    ['The links.0.url field must be a valid URL.', 'Enter a valid HTTP or HTTPS URL.'],
    ['The links.0.url field has a duplicate value.', 'Remove duplicate keywords or URLs.'],
    ['Secondary keywords must differ from the primary keyword.', 'Secondary keywords must differ from the primary keyword.'],
    ['The links field must not have more than 10 items.', 'Use no more than {{max}} items.'],
  ]) assert.equal(validationMessage([error], translated), `localized:${expected}`)
})
