import assert from 'node:assert/strict'
import test from 'node:test'
import { languageVersionStatus, mergeContentMutation, mergeContentSnapshot, selectedGroupContents } from '../src/lib/contentVersions.js'

const english = {
  id: 101, content_group_id: 42, content_language: 'en', primary_language: 'en',
  title: 'English title', generated_content: 'English output', created_at: '2026-09-30T12:00:00Z',
  is_generation_stale: false,
  translations: [{ id: 101, content_language: 'en', has_generated_content: true, is_generation_stale: false }],
}
const german = {
  ...english, id: 118, content_language: 'de', title: 'German draft', generated_content: null,
  translations: [...english.translations, { id: 118, content_language: 'de', has_generated_content: false, is_generation_stale: false }],
}
const otherGroup = { ...english, id: 200, content_group_id: 99, title: 'Separate article' }

test('group selection defaults to primary and falls back after the selected version is deleted', () => {
  assert.deepEqual(selectedGroupContents([german, english, otherGroup], {}).map(content => content.id), [200, 101])
  assert.deepEqual(selectedGroupContents([german, english, otherGroup], { 42: 118 }).map(content => content.id), [200, 118])
  const remaining = mergeContentMutation([english, german, otherGroup], german, null)
  assert.deepEqual(selectedGroupContents(remaining, { 42: 118 }).map(content => content.id), [200, 101])
  assert.deepEqual(selectedGroupContents([german], { 42: 101 }).map(content => content.id), [118])
})

test('adding a draft updates sibling language links while preserving each version and other groups', () => {
  const result = mergeContentMutation([english, otherGroup], english, german)
  assert.equal(result.length, 3)
  assert.equal(result.find(content => content.id === 101).generated_content, 'English output')
  assert.equal(result.find(content => content.id === 118).generated_content, null)
  assert.deepEqual(result.find(content => content.id === 101).translations, german.translations)
  assert.strictEqual(result.find(content => content.id === 200), otherGroup)
  assert.deepEqual(english.translations, [{ id: 101, content_language: 'en', has_generated_content: true, is_generation_stale: false }])
  assert.equal(languageVersionStatus(result.find(content => content.id === 101).translations[1]), 'draft')
})

test('editing a version synchronizes primary and available languages without replacing sibling text', () => {
  const updated = { ...english, content_language: 'uk', primary_language: 'uk',
    translations: [{ id: 101, content_language: 'uk' }, { id: 118, content_language: 'de' }] }
  const result = mergeContentMutation([english, german], english, updated)
  assert.equal(result.find(content => content.id === 118).title, 'German draft')
  assert.ok(result.every(content => content.primary_language === 'uk'))
  assert.ok(result.every(content => content.translations[0].content_language === 'uk'))
})

test('deleting the primary promotes the lowest remaining Content ID, not array order', () => {
  const ukrainian = { ...german, id: 133, content_language: 'uk' }
  const result = mergeContentMutation([ukrainian, german, english], english, null)
  assert.equal(result.length, 2)
  assert.ok(result.every(content => content.primary_language === 'de'))
  assert.deepEqual(result[0].translations, [
    { id: 118, content_language: 'de', has_generated_content: false, is_generation_stale: false },
    { id: 133, content_language: 'uk', has_generated_content: false, is_generation_stale: false },
  ])
})

test('deleting secondary and final versions preserves unrelated groups', () => {
  const result = mergeContentMutation([english, german, otherGroup], german, null)
  assert.equal(result.find(content => content.id === 101).primary_language, 'en')
  assert.deepEqual(result.find(content => content.id === 101).translations, english.translations)
  assert.deepEqual(mergeContentMutation(result, english, null), [otherGroup])
})

test('an older list response cannot discard a newly added translation or restore a deleted one', () => {
  const started = new Map()
  const revisions = new Map([[42, 1]])
  const added = mergeContentMutation([english], english, german)
  assert.deepEqual(mergeContentSnapshot(added, [english], revisions, started), added)
  assert.deepEqual(mergeContentSnapshot([otherGroup], [english, otherGroup], revisions, started), [otherGroup])
})

test('a fresh list response replaces outdated group data', () => {
  const revisions = new Map([[42, 1]])
  const result = mergeContentSnapshot([english], [german, english], revisions, new Map(revisions))
  assert.deepEqual(result.map(content => content.id), [118, 101])
})

test('version status distinguishes existence, empty output, current output, and stale output', () => {
  assert.equal(languageVersionStatus(undefined), 'missing')
  for (const generated_content of [null, '', '   ']) {
    assert.equal(languageVersionStatus({ generated_content, is_generation_stale: true }), 'draft')
  }
  assert.equal(languageVersionStatus({ has_generated_content: false, is_generation_stale: true }), 'draft')
  assert.equal(languageVersionStatus({ has_generated_content: true, is_generation_stale: false }), 'generated')
  assert.equal(languageVersionStatus({ has_generated_content: true, is_generation_stale: true }), 'stale')
})

test('pending generation overrides completion only for its Content ID until the request settles', () => {
  const version = { id: 118, has_generated_content: true, is_generation_stale: false }
  assert.equal(languageVersionStatus(version, new Set([118])), 'pending')
  assert.equal(languageVersionStatus(version, new Set([101])), 'generated')
  assert.equal(languageVersionStatus(version, new Set()), 'generated')
  assert.equal(languageVersionStatus(version), 'generated')
})

test('generation and edit responses propagate changed status to sibling controls', () => {
  const generated = { ...german, generated_content: 'German output', translations: [
    english.translations[0], { ...german.translations[1], has_generated_content: true },
  ] }
  const result = mergeContentMutation([english, german], german, generated)
  assert.equal(languageVersionStatus(result.find(content => content.id === 101).translations[1]), 'generated')
  const stale = { ...generated, is_generation_stale: true, translations: [
    english.translations[0], { ...generated.translations[1], is_generation_stale: true },
  ] }
  const edited = mergeContentMutation(result, generated, stale)
  assert.equal(languageVersionStatus(edited.find(content => content.id === 101).translations[1]), 'stale')
  const deleted = mergeContentMutation(edited, english, null)
  assert.equal(languageVersionStatus(deleted[0].translations[0]), 'stale')
})
