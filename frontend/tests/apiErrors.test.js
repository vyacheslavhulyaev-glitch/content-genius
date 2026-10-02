import assert from 'node:assert/strict'
import { test } from 'node:test'
import { requireSuccess } from '../src/lib/api.js'

test('application error codes propagate independently of raw error text', async () => {
  await assert.rejects(requireSuccess({
    ok: false, status: 422, json: async () => ({ code: 'moderation_input_blocked', error: 'Sensitive text' }),
  }), failure => {
    assert.equal(failure.code, 'moderation_input_blocked')
    assert.equal(failure.status, 422)
    assert.deepEqual(failure.errors, {})
    assert.doesNotMatch(failure.message, /Sensitive/)
    return true
  })
})

test('invalid error bodies retain the safe fallback', async () => {
  await assert.rejects(requireSuccess({
    ok: false, status: 503, json: async () => { throw new Error('Malformed body') },
  }), failure => failure.status === 503 && failure.code === undefined)
})
