export default function AppHeader({ user, page, onNavigate, onLogout, busy }) {
  return (
    <header className="app-header">
      <div className="header-inner">
        <span className="brand"><span className="brand-mark" aria-hidden="true">C</span>ContentGenius</span>
        <nav aria-label="Main navigation">
          <button type="button" className="nav-button" aria-current={page === 'dashboard' ? 'page' : undefined}
            onClick={() => onNavigate('dashboard')}>Dashboard</button>
          {user.is_admin === true && (
            <button type="button" className="nav-button" aria-current={page === 'admin' ? 'page' : undefined}
              onClick={() => onNavigate('admin')}>Admin</button>
          )}
        </nav>
        <div className="account">
          <div className="account-info"><strong>{user.name}</strong><span>{user.email}</span></div>
          <button type="button" className="secondary" disabled={busy} onClick={onLogout}>
            {busy ? 'Signing out...' : 'Logout'}
          </button>
        </div>
      </div>
    </header>
  )
}
