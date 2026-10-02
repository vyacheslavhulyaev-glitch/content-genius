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

test('quota errors expose retry and reset metadata without exposing provider text', async () => {
  await assert.rejects(requireSuccess({
    ok: false, status: 429, json: async () => ({ code: 'generation_rate_limited', retry_after: 125,
      reset_at: '2026-10-02T12:00:00Z', error: 'Sensitive provider text' }),
  }), failure => {
    assert.equal(failure.code, 'generation_rate_limited')
    assert.equal(failure.retryAfter, 125)
    assert.equal(failure.resetAt, '2026-10-02T12:00:00Z')
    assert.doesNotMatch(failure.message, /Sensitive/)
    return true
  })
})
