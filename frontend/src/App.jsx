import { useEffect, useState } from 'react'
import './App.css'

const backendUrl = 'http://localhost:8000'

async function request(path, options = {}) {
  try {
    return await fetch(`${backendUrl}${path}`, {
      ...options,
      credentials: 'include',
      headers: { Accept: 'application/json', ...options.headers },
    })
  } catch {
    throw new Error(`${path}: Network request failed. Check Laravel and CORS settings.`)
  }
}

async function requireSuccess(response) {
  if (!response.ok) {
    const body = await response.json().catch(() => null)
    const failure = new Error(`HTTP ${response.status}: ${body?.message || response.statusText || 'Request failed'}`)
    failure.status = response.status
    failure.errors = body?.errors || {}
    throw failure
  }
}

function csrfToken() {
  const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))
  if (!cookie) {
    throw new Error('XSRF-TOKEN cookie is missing. Check cookie settings and use localhost for both servers.')
  }
  return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
}

async function currentUser() {
  const response = await request('/api/user')
  if (response.status === 401) return null
  await requireSuccess(response)
  return response.json()
}

function DraftForm({ busy, onBusyChange, onSessionExpired }) {
  const [fields, setFields] = useState({ title: '', topic: '', tone: '', length: '' })
  const [draft, setDraft] = useState(null)
  const [status, setStatus] = useState('')
  const [error, setError] = useState('')
  const [validationErrors, setValidationErrors] = useState({})

  async function createDraft(event) {
    event.preventDefault()
    onBusyChange(true)
    setDraft(null)
    setError('')
    setValidationErrors({})
    setStatus('Creating draft...')

    const payload = { title: fields.title.trim(), topic: fields.topic.trim() }
    for (const field of ['tone', 'length']) {
      if (fields[field].trim()) payload[field] = fields[field].trim()
    }

    try {
      const response = await request('/api/contents', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        body: JSON.stringify(payload),
      })
      await requireSuccess(response)
      setDraft(await response.json())
      setStatus('Draft created successfully.')
    } catch (failure) {
      if (failure.status === 401) {
        onSessionExpired()
        return
      }
      setStatus('Draft creation could not be confirmed.')
      setError(failure.status === 419 ? `${failure.message} Log in again to refresh the session.` : failure.message)
      setValidationErrors(failure.errors || {})
    } finally {
      onBusyChange(false)
    }
  }

  return (
    <section aria-labelledby="draft-heading">
      <h2 id="draft-heading">Create content draft</h2>
      <form onSubmit={createDraft}>
        {['title', 'topic', 'tone', 'length'].map((field) => (
          <div className="draft-field" key={field}>
            <label htmlFor={`draft-${field}`}>
              {field.charAt(0).toUpperCase() + field.slice(1)}
              {['tone', 'length'].includes(field) && ' (optional)'}
            </label>
            <input id={`draft-${field}`} name={field} type="text" maxLength={255}
              required={['title', 'topic'].includes(field)} disabled={busy}
              value={fields[field]}
              onChange={(event) => setFields({ ...fields, [field]: event.target.value })}
              aria-invalid={Boolean(validationErrors[field])}
              aria-describedby={validationErrors[field] ? `draft-${field}-error` : undefined} />
            {validationErrors[field] && (
              <p id={`draft-${field}-error`} role="alert">{validationErrors[field].join(' ')}</p>
            )}
          </div>
        ))}
        <button type="submit" disabled={busy}>Create draft</button>
      </form>
      <p role="status">{status}</p>
      {error && <p role="alert">{error}</p>}
      {draft && (
        <>
          <h3>Created content</h3>
          <pre>{JSON.stringify(draft, null, 2)}</pre>
        </>
      )}
    </section>
  )
}

function App() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [user, setUser] = useState(undefined)
  const [busy, setBusy] = useState(true)
  const [status, setStatus] = useState('Checking session…')
  const [error, setError] = useState('')

  useEffect(() => {
    let active = true
    currentUser()
      .then((authenticatedUser) => {
        if (!active) return
        setUser(authenticatedUser)
        setStatus(authenticatedUser ? 'Authenticated: /api/user returned 200.' : 'Not authenticated: /api/user returned 401.')
      })
      .catch((failure) => {
        if (!active) return
        setStatus('Session check failed.')
        setError(failure.message)
      })
      .finally(() => {
        if (active) setBusy(false)
      })
    return () => { active = false }
  }, [])

  async function login(event) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setUser(undefined)
    setStatus('Initializing CSRF cookie…')
    try {
      await requireSuccess(await request('/sanctum/csrf-cookie'))
      setStatus('Logging in…')
      await requireSuccess(await request('/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        body: JSON.stringify({ email, password }),
      }))
      setPassword('')
      setStatus('Checking authenticated user…')
      const authenticatedUser = await currentUser()
      setUser(authenticatedUser)
      if (!authenticatedUser) throw new Error('Login returned success, but /api/user returned 401.')
      setStatus('Login confirmed: /api/user returned 200.')
    } catch (failure) {
      setStatus('Login could not be confirmed.')
      setError(failure.message)
    } finally {
      setBusy(false)
    }
  }

  async function logout() {
    setBusy(true)
    setError('')
    setStatus('Logging out…')
    try {
      await requireSuccess(await request('/logout', {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': csrfToken() },
      }))
      setUser(undefined)
      setStatus('Checking that the session ended…')
      const authenticatedUser = await currentUser()
      setUser(authenticatedUser)
      if (authenticatedUser) throw new Error('Logout returned success, but /api/user is still authenticated.')
      setStatus('Logout confirmed: /api/user returned 401.')
    } catch (failure) {
      setStatus('Logout could not be confirmed.')
      setError(failure.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <main>
      <h1>Session authentication smoke test</h1>
      <form onSubmit={login}>
        <label htmlFor="email">Email</label>
        <input id="email" name="email" type="email" autoComplete="username" required
          value={email} onChange={(event) => setEmail(event.target.value)} disabled={busy} />
        <label htmlFor="password">Password</label>
        <input id="password" name="password" type="password" autoComplete="current-password" required
          value={password} onChange={(event) => setPassword(event.target.value)} disabled={busy} />
        <button type="submit" disabled={busy}>Login</button>
      </form>
      <h2>Current user</h2>
      {user ? <pre>{JSON.stringify(user, null, 2)}</pre> : <p>{user === null ? 'Not authenticated.' : 'Session not verified.'}</p>}
      <button type="button" onClick={logout} disabled={busy}>Logout</button>
      <p role="status">{status}</p>
      {error && <p role="alert">{error}</p>}
      {user && (
        <DraftForm key={user.id} busy={busy} onBusyChange={setBusy}
          onSessionExpired={() => {
            setUser(null)
            setStatus('Session expired. Log in again to create a draft.')
          }} />
      )}
    </main>
  )
}

export default App
