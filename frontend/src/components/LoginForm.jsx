import { useRef, useState } from 'react'

export default function LoginForm({ busy, status, error, onLogin }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const pending = useRef(false)

  async function submit(event) {
    event.preventDefault()
    if (busy || pending.current) return
    pending.current = true
    try {
      await onLogin(email, password)
    } finally {
      setPassword('')
      pending.current = false
    }
  }

  return (
    <main className="login-page">
      <div className="login-intro">
        <span className="brand"><span className="brand-mark" aria-hidden="true">C</span>ContentGenius</span>
        <p>Turn your ideas into content worth sharing.</p>
      </div>
      <section className="panel login-panel" aria-labelledby="login-heading">
        <h1 id="login-heading">Welcome back</h1>
        <p className="muted">Sign in to your content workspace.</p>
        <form onSubmit={submit}>
          <div className="draft-field">
            <label htmlFor="email">Email</label>
            <input id="email" name="email" type="email" autoComplete="username" required
              value={email} onChange={(event) => setEmail(event.target.value)} disabled={busy} />
          </div>
          <div className="draft-field">
            <label htmlFor="password">Password</label>
            <input id="password" name="password" type="password" autoComplete="current-password" required
              value={password} onChange={(event) => setPassword(event.target.value)} disabled={busy} />
          </div>
          <button className="primary" type="submit" disabled={busy}>{busy ? 'Please wait...' : 'Sign in'}</button>
        </form>
        {status && <p role="status">{status}</p>}
        {error && <p role="alert">{error}</p>}
      </section>
      <p className="login-caption">A little direction. A fresh draft. Your next great idea.</p>
    </main>
  )
}
