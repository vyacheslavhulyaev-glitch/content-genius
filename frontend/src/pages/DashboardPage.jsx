import { useState } from 'react'
import DraftForm from '../components/DraftForm'
import ContentList from '../components/ContentList'

export default function DashboardPage({ onSessionExpired }) {
  const [listVersion, setListVersion] = useState(0)
  return (
    <>
      <div className="page-heading">
        <p className="eyebrow">Your workspace</p>
        <h1>Bring your next idea to life</h1>
        <p className="muted">Create a draft, set the direction, and let AI help with the words.</p>
      </div>
      <div className="dashboard-grid">
        <DraftForm onCreated={() => setListVersion((version) => version + 1)} onSessionExpired={onSessionExpired} />
        <ContentList refreshVersion={listVersion} onSessionExpired={onSessionExpired} />
      </div>
    </>
  )
}
