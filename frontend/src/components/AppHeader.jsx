import { useTranslation } from 'react-i18next'
import LanguageSwitcher from './LanguageSwitcher'

export default function AppHeader({ user, page, onNavigate, onLogout, busy }) {
  const { t } = useTranslation()
  return (
    <header className="app-header">
      <div className="header-inner">
        <span className="brand"><span className="brand-mark" aria-hidden="true">C</span>ContentGenius</span>
        <nav aria-label={t('Main navigation')}>
          {user.is_admin_demo !== true && <button type="button" className="nav-button" aria-current={page === 'dashboard' ? 'page' : undefined}
            onClick={() => onNavigate('dashboard')}>{t('Dashboard')}</button>}
          {(user.is_admin === true || user.is_admin_demo === true) && (
            <button type="button" className="nav-button" aria-current={page === 'admin' ? 'page' : undefined}
              onClick={() => onNavigate('admin')}>{t('Admin')}</button>
          )}
        </nav>
        <div className="account">
          <LanguageSwitcher />
          <div className="account-info"><strong>{user.name}</strong><span>{user.email}</span></div>
          <button type="button" className="secondary" disabled={busy} onClick={onLogout}>
            {busy ? t('Signing out...') : t('Logout')}
          </button>
        </div>
      </div>
    </header>
  )
}
