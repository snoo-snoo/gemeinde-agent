import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorText, Field, Notice, PageHeader, controlClass } from '../components/ui'
import { label, when } from '../format'
import { useMunicipality } from '../municipality'
import type { Municipality, Schedule, User } from '../types'

export function SettingsPage() {
  const { isAdmin, canWrite } = useAuth()
  const { selectedId } = useMunicipality()
  const client = useQueryClient()
  const municipalities = useQuery({ queryKey: ['municipalities'], queryFn: () => api<Municipality[]>('/api/municipalities') })
  const schedules = useQuery({ queryKey: ['schedules'], queryFn: () => api<Schedule[]>('/api/schedules') })
  const users = useQuery({ queryKey: ['users'], enabled: isAdmin, queryFn: () => api<User[]>('/api/users') })
  const createMunicipality = useMutation({
    mutationFn: (body: Record<string, string>) => api('/api/municipalities', { method: 'POST', body: JSON.stringify(body) }),
    onSuccess: () => client.invalidateQueries({ queryKey: ['municipalities'] }),
  })
  const createSource = useMutation({
    mutationFn: (body: Record<string, unknown>) => api('/api/sources', { method: 'POST', body: JSON.stringify(body) }),
    onSuccess: () => client.invalidateQueries({ queryKey: ['municipalities'] }),
  })
  const createUser = useMutation({
    mutationFn: (body: Record<string, string>) => api('/api/users', { method: 'POST', body: JSON.stringify(body) }),
    onSuccess: () => client.invalidateQueries({ queryKey: ['users'] }),
  })

  return (
    <section className="grid gap-6">
      <PageHeader title="Einstellungen" lede="Gemeinden, Quellen und Zeitpläne sind je Gebietskörperschaft getrennt. Der Pilot startet mit Aigen-Schlägl und einem Download-Limit, damit der erste Lauf überprüfbar bleibt." />
      <ErrorText error={municipalities.error ?? schedules.error ?? createMunicipality.error ?? createSource.error ?? createUser.error} />
      <article className="rounded-lg border border-line bg-card p-4">
        <h2 className="font-serif text-xl">Gemeinden</h2>
        <ul className="mt-3 grid gap-2 text-sm">
          {(municipalities.data ?? []).map((item) => (
            <li key={item.id}>{item.official_name} · {item.bundesland} · {item.documents_count ?? 0} Dokumente</li>
          ))}
        </ul>
        {canWrite ? <MunicipalityForm onSubmit={(body) => createMunicipality.mutate(body)} /> : null}
      </article>
      {canWrite ? <SourceForm municipalityId={selectedId} onSubmit={(body) => createSource.mutate(body)} /> : null}
      <article className="rounded-lg border border-line bg-card p-4">
        <h2 className="font-serif text-xl">Abrufzeitpläne</h2>
        <ul className="mt-3 grid gap-2 text-sm">
          {(schedules.data ?? []).map((schedule) => (
            <li key={schedule.id} className="flex flex-wrap items-center justify-between gap-2">
              <span>Gemeinde {schedule.municipality_id} · {schedule.cron} · {schedule.enabled ? 'aktiv' : 'aus'} · zuletzt {when(schedule.last_ran_at)}</span>
              {isAdmin ? (
                <button type="button" className="underline" onClick={() => void api(`/api/schedules/${schedule.id}`, { method: 'PATCH', body: JSON.stringify({ enabled: !schedule.enabled }) }).then(() => client.invalidateQueries({ queryKey: ['schedules'] }))}>
                  {schedule.enabled ? 'Deaktivieren' : 'Aktivieren'}
                </button>
              ) : null}
            </li>
          ))}
        </ul>
        <p className="mt-3 text-sm">Aktivierte Zeitpläne laufen über `php artisan schedule:work` und stellen einen Prüflauf in die Queue. Der Pilotzeitplan ist absichtlich deaktiviert.</p>
      </article>
      {isAdmin ? (
        <article className="rounded-lg border border-line bg-card p-4">
          <h2 className="font-serif text-xl">Benutzer</h2>
          <ul className="mt-3 grid gap-1 text-sm">
            {(users.data ?? []).map((user) => <li key={user.id}>{user.name} · {user.email} · {label(user.role)}</li>)}
          </ul>
          <UserForm onSubmit={(body) => createUser.mutate(body)} />
        </article>
      ) : <Notice>Benutzerverwaltung ist der Rolle Verwaltung vorbehalten.</Notice>}
    </section>
  )
}

function MunicipalityForm({ onSubmit }: { onSubmit: (body: Record<string, string>) => void }) {
  const [name, setName] = useState('')
  const [official, setOfficial] = useState('')
  const [bundesland, setBundesland] = useState('Oberösterreich')
  function submit(event: FormEvent) {
    event.preventDefault()
    onSubmit({ name, official_name: official, bundesland })
    setName('')
    setOfficial('')
  }
  return (
    <form className="mt-4 grid gap-3 md:grid-cols-3" onSubmit={submit}>
      <Field labelText="Kurzname"><input className={controlClass} required value={name} onChange={(event) => setName(event.target.value)} /></Field>
      <Field labelText="Amtlicher Name"><input className={controlClass} required value={official} onChange={(event) => setOfficial(event.target.value)} /></Field>
      <Field labelText="Bundesland"><input className={controlClass} required value={bundesland} onChange={(event) => setBundesland(event.target.value)} /></Field>
      <button className="w-fit rounded-md bg-forest px-3 py-2 text-paper" type="submit">Gemeinde anlegen</button>
    </form>
  )
}

function SourceForm({ municipalityId, onSubmit }: { municipalityId: number | null; onSubmit: (body: Record<string, unknown>) => void }) {
  const [name, setName] = useState('Finanzseite')
  const [url, setUrl] = useState('')
  const [host, setHost] = useState('')
  function submit(event: FormEvent) {
    event.preventDefault()
    if (!municipalityId) return
    onSubmit({ municipality_id: municipalityId, name, url, source_type: 'finance_page', allowed_hosts: [host], max_documents: 2 })
  }
  return (
    <article className="rounded-lg border border-line bg-card p-4">
      <h2 className="font-serif text-xl">Quelle für die gewählte Gemeinde</h2>
      <form className="mt-3 grid gap-3 md:grid-cols-3" onSubmit={submit}>
        <Field labelText="Name"><input className={controlClass} required value={name} onChange={(event) => setName(event.target.value)} /></Field>
        <Field labelText="URL"><input className={controlClass} type="url" required value={url} onChange={(event) => setUrl(event.target.value)} /></Field>
        <Field labelText="Erlaubter Host"><input className={controlClass} required value={host} onChange={(event) => setHost(event.target.value)} /></Field>
        <button className="w-fit rounded-md bg-forest px-3 py-2 text-paper" type="submit" disabled={!municipalityId}>Quelle speichern</button>
      </form>
    </article>
  )
}

function UserForm({ onSubmit }: { onSubmit: (body: Record<string, string>) => void }) {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [role, setRole] = useState('viewer')
  function submit(event: FormEvent) {
    event.preventDefault()
    onSubmit({ name, email, password, role })
    setPassword('')
  }
  return (
    <form className="mt-4 grid gap-3 md:grid-cols-2" onSubmit={submit}>
      <Field labelText="Name"><input className={controlClass} required value={name} onChange={(event) => setName(event.target.value)} /></Field>
      <Field labelText="E-Mail"><input className={controlClass} type="email" required value={email} onChange={(event) => setEmail(event.target.value)} /></Field>
      <Field labelText="Passwort, mindestens 12 Zeichen"><input className={controlClass} type="password" minLength={12} required value={password} onChange={(event) => setPassword(event.target.value)} /></Field>
      <Field labelText="Rolle">
        <select className={controlClass} value={role} onChange={(event) => setRole(event.target.value)}>
          {['viewer', 'analyst', 'reviewer', 'admin'].map((item) => <option key={item} value={item}>{label(item)}</option>)}
        </select>
      </Field>
      <button className="w-fit rounded-md bg-forest px-3 py-2 text-paper" type="submit">Benutzer anlegen</button>
    </form>
  )
}
