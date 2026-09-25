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
    throw new Error(`HTTP ${response.status}: ${body?.message || response.statusText || 'Request failed'}`)
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
    </main>
  )
}

export default App
