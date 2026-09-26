import { useCallback, useEffect, useState } from 'react'
import { request, requireSuccess, csrfToken, currentUser } from './lib/api'
import AppHeader from './components/AppHeader'
import LoginForm from './components/LoginForm'
import DashboardPage from './pages/DashboardPage'
import AdminPage from './pages/AdminPage'
import './App.css'

export default function App() {
  const [user, setUser] = useState(undefined)
  const [busy, setBusy] = useState(true)
  const [page, setPage] = useState('dashboard')
  const [status, setStatus] = useState('Checking your session...')
  const [error, setError] = useState('')

  const handleSessionExpired = useCallback(() => {
    setUser(null)
    setPage('dashboard')
    setError('')
    setStatus('Session expired. Log in again to access your drafts.')
  }, [])

  useEffect(() => {
    let active = true
    currentUser()
      .then((authenticatedUser) => {
        if (!active) return
        setUser(authenticatedUser)
        setStatus('')
      })
      .catch(() => {
        if (!active) return
        setStatus('')
        setError('Could not check your session. Please log in again.')
      })
      .finally(() => { if (active) setBusy(false) })
    return () => { active = false }
  }, [])

  async function login(email, password) {
    setBusy(true)
    setError('')
    setStatus('Signing in...')
    try {
      await requireSuccess(await request('/sanctum/csrf-cookie'))
      await requireSuccess(await request('/login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        body: JSON.stringify({ email, password }),
      }))
      const authenticatedUser = await currentUser()
      if (!authenticatedUser) throw new Error('Session not confirmed')
      setPage('dashboard')
      setUser(authenticatedUser)
      setStatus('')
    } catch (failure) {
      setStatus('')
      setError(failure.status === 422 ? 'The email or password is incorrect.'
        : failure.status === 429 ? 'Too many login attempts. Please wait a minute.'
          : 'Could not sign in. Please try again.')
    } finally {
      setBusy(false)
    }
  }

  async function logout() {
    setBusy(true)
    setError('')
    setStatus('Signing out...')
    try {
      await requireSuccess(await request('/logout', {
        method: 'POST', headers: { 'X-XSRF-TOKEN': csrfToken() },
      }))
      setUser(undefined)
      setPage('dashboard')
      const authenticatedUser = await currentUser()
      setUser(authenticatedUser)
      if (authenticatedUser) throw new Error('Session still active')
      setStatus('You have been signed out.')
    } catch (failure) {
      if (failure.status === 401) {
        handleSessionExpired()
      } else {
        setStatus('')
        setError('Could not confirm sign out. Please try again.')
      }
    } finally {
      setBusy(false)
    }
  }

  if (!user) return <LoginForm busy={busy} status={status} error={error} onLogin={login} />

  return (
    <>
      <a className="skip-link" href="#main-content">Skip to content</a>
      <AppHeader user={user} page={page} onNavigate={setPage} onLogout={logout} busy={busy} />
      <main id="main-content" className="workspace">
        {status && <p role="status">{status}</p>}
        {error && <p role="alert">{error}</p>}
        <div hidden={page !== 'dashboard'}>
          <DashboardPage key={user.id} onSessionExpired={handleSessionExpired} />
        </div>
        {page === 'admin' && user.is_admin === true && (
          <AdminPage key={user.id} onSessionExpired={handleSessionExpired} />
        )}
      </main>
    </>
  )
}
