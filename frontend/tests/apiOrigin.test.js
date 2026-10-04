import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createServer } from 'vite'

for (const [name, environment, expected] of [
  ['local development', { PROD: false }, 'http://localhost:8000/api/user'],
  ['production same origin', { PROD: true }, '/api/user'],
  ['explicit origin override', { PROD: true, VITE_API_BASE_URL: 'https://contentgenius.example.com/' }, 'https://contentgenius.example.com/api/user'],
]) {
  test(`API requests use ${name} and retain session credentials`, async () => {
    const server = await createServer({
      configFile: false, cacheDir: 'node_modules/.vite-api-origin',
      optimizeDeps: { noDiscovery: true, include: [] },
      server: { middlewareMode: true, hmr: false, ws: false, watch: null }, appType: 'custom',
      define: { 'import.meta.env': JSON.stringify(environment) },
    })
    const originalFetch = globalThis.fetch
    try {
      let sent
      globalThis.fetch = async (url, options) => { sent = { url, options }; return { ok: true } }
      const { request } = await server.ssrLoadModule('/src/lib/api.js')
      await request('/api/user')
      assert.equal(sent.url, expected)
      assert.equal(sent.options.credentials, 'include')
      assert.equal(sent.options.headers.Accept, 'application/json')
    } finally {
      globalThis.fetch = originalFetch
      await server.close()
    }
  })
}
