import { useTranslation } from 'react-i18next'
import { useRef, useState } from 'react'
import LanguageSwitcher from './LanguageSwitcher'

export default function LoginForm({ busy, status, error, onLogin }) {
  const { t } = useTranslation()
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
      <LanguageSwitcher />
      <div className="login-intro">
        <span className="brand"><span className="brand-mark" aria-hidden="true">C</span>ContentGenius</span>
        <p>{t('Turn your ideas into content worth sharing.')}</p>
      </div>
      <section className="panel login-panel" aria-labelledby="login-heading">
        <h1 id="login-heading">{t('Welcome back')}</h1>
        <p className="muted">{t('Sign in to your content workspace.')}</p>
        <form onSubmit={submit}>
          <div className="draft-field">
            <label htmlFor="email">{t('Email')}</label>
            <input id="email" name="email" type="email" autoComplete="username" required
              value={email} onChange={(event) => setEmail(event.target.value)} disabled={busy} />
          </div>
          <div className="draft-field">
            <label htmlFor="password">{t('Password')}</label>
            <input id="password" name="password" type="password" autoComplete="current-password" required
              value={password} onChange={(event) => setPassword(event.target.value)} disabled={busy} />
          </div>
          <button className="primary" type="submit" disabled={busy}>{busy ? t('Please wait...') : t('Sign in')}</button>
        </form>
        {status && <p role="status">{t(status)}</p>}
        {error && <p role="alert">{t(error)}</p>}
      </section>
      <p className="login-caption">{t('A little direction. A fresh draft. Your next great idea.')}</p>
    </main>
  )
}
