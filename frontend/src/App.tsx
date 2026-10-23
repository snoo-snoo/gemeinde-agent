import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './auth'
import { Shell } from './components/Shell'
import { MunicipalityProvider } from './municipality'
import { AgentsPage } from './pages/AgentsPage'
import { AnalysisPage } from './pages/AnalysisPage'
import { DocumentsPage } from './pages/DocumentsPage'
import { FindingsPage } from './pages/FindingsPage'
import { LoginPage } from './pages/LoginPage'
import { OverviewPage } from './pages/OverviewPage'
import { ProvidersPage } from './pages/ProvidersPage'
import { QuestionsPage } from './pages/QuestionsPage'
import { ReportDetailPage, ReportsPage } from './pages/ReportsPage'
import { SettingsPage } from './pages/SettingsPage'

export default function App() {
  const { user, ready } = useAuth()
  if (!ready) return <p className="p-6">Anmeldung wird geprüft.</p>
  return (
    <Routes>
      <Route path="/anmelden" element={<LoginPage />} />
      <Route element={user ? <MunicipalityProvider><Shell /></MunicipalityProvider> : <Navigate to="/anmelden" replace />}>
        <Route index element={<OverviewPage />} />
        <Route path="dokumente" element={<DocumentsPage />} />
        <Route path="analyse" element={<AnalysisPage />} />
        <Route path="befunde" element={<FindingsPage />} />
        <Route path="fragen" element={<QuestionsPage />} />
        <Route path="berichte" element={<ReportsPage />} />
        <Route path="berichte/:id" element={<ReportDetailPage />} />
        <Route path="agenten" element={<AgentsPage />} />
        <Route path="anbieter" element={<ProvidersPage />} />
        <Route path="einstellungen" element={<SettingsPage />} />
      </Route>
    </Routes>
  )
}
