import assert from 'node:assert/strict'

// Run with Node 24 on the verification host; the production image does not need Node.
const baseUrl = process.env.APP_URL?.replace(/\/+$/, '')
const email = process.env.DEMO_EMAIL
const password = process.env.DEMO_PASSWORD
assert.ok(baseUrl && email && password, 'APP_URL, DEMO_EMAIL and DEMO_PASSWORD are required.')
const cookies = new Map()

async function request(path, options = {}) {
  const response = await fetch(`${baseUrl}${path}`, {
    ...options,
    redirect: 'manual',
    headers: {
      Accept: 'application/json', Origin: baseUrl, Referer: `${baseUrl}/`,
      Cookie: [...cookies].map(([key, value]) => `${key}=${value}`).join('; '),
      ...options.headers,
    },
  })
  for (const cookie of response.headers.getSetCookie()) {
    const pair = cookie.split(';', 1)[0]
    const separator = pair.indexOf('=')
    cookies.set(pair.slice(0, separator), pair.slice(separator + 1))
  }
  return response
}

const page = await request('/')
assert.equal(page.status, 200, 'The built frontend must be served.')
const html = await page.text()
assert.match(html, /<title>ContentGenius<\/title>/)
assert.match(html, /\/assets\//)
assert.doesNotMatch(html, /@vite\/client|localhost:5173/)
const script = html.match(/src="([^" ]+\.js)"/)[1]
const asset = await request(script)
assert.equal(asset.status, 200)
assert.doesNotMatch(await asset.text(), /http:\/\/localhost:8000/)
const health = await request('/api/health', { headers: { Origin: '', Referer: '' } })
assert.equal(health.status, 200)
assert.deepEqual(await health.json(), { status: 'ok' })
assert.equal((await request('/api/contents')).status, 401)
assert.equal((await request('/sanctum/csrf-cookie')).status, 204)
const csrf = () => decodeURIComponent(cookies.get('XSRF-TOKEN'))
const login = await request('/login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf() },
  body: JSON.stringify({ email, password }),
})
assert.equal(login.status, 204, 'Session login must succeed with CSRF protection enabled.')
const user = await request('/api/user')
assert.equal(user.status, 200)
assert.equal((await user.json()).email, email)
const contents = await request('/api/contents')
assert.equal(contents.status, 200, 'Authenticated content list must be accessible.')
assert.ok(Array.isArray(await contents.json()))
const logout = await request('/logout', { method: 'POST', headers: { 'X-XSRF-TOKEN': csrf() } })
assert.equal(logout.status, 204)
assert.equal((await request('/api/user')).status, 401)
console.log('Production smoke passed: built SPA/assets, health, CSRF login, user, content list and logout. No generation endpoints called.')
