import { useState, type FormEvent } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../auth'
import { ErrorText, Field, controlClass } from '../components/ui'

export function LoginPage() {
  const { user, login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<unknown>(null)
  const [pending, setPending] = useState(false)

  if (user) return <Navigate to="/" replace />

  async function submit(event: FormEvent) {
    event.preventDefault()
    setPending(true)
    setError(null)
    try {
      await login(email, password)
    } catch (caught) {
      setError(caught)
    } finally {
      setPending(false)
    }
  }

  return (
    <main className="mx-auto grid min-h-screen max-w-lg place-content-center px-4">
      <form onSubmit={(event) => void submit(event)} className="grid gap-4 rounded-lg border border-line bg-card p-6">
        <div>
          <h1 className="font-serif text-3xl text-forest">KommunalAudit AI</h1>
          <p className="mt-2 text-sm text-ink/80">Anmeldung für die Prüfung veröffentlichter Gemeindefinanzen. Auffälligkeiten sind Prüfhinweise und keine Schuldzuweisung.</p>
        </div>
        <ErrorText error={error} />
        <Field labelText="E-Mail">
          <input className={controlClass} type="email" autoComplete="username" required value={email} onChange={(event) => setEmail(event.target.value)} />
        </Field>
        <Field labelText="Passwort">
          <input className={controlClass} type="password" autoComplete="current-password" required value={password} onChange={(event) => setPassword(event.target.value)} />
        </Field>
        <button className="rounded-md bg-forest px-4 py-2 text-paper" type="submit" disabled={pending}>
          {pending ? 'Anmeldung läuft' : 'Anmelden'}
        </button>
      </form>
    </main>
  )
}
