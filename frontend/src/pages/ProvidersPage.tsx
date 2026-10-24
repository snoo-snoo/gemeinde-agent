import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorText, Field, Notice, PageHeader, controlClass } from '../components/ui'
import type { Provider } from '../types'

const DRIVERS = ['openai', 'anthropic', 'gemini', 'xai', 'openrouter', 'openai_compatible']

export function ProvidersPage() {
  const { isAdmin } = useAuth()
  const client = useQueryClient()
  const providers = useQuery({ queryKey: ['providers'], queryFn: () => api<Provider[]>('/api/providers') })
  const refresh = () => client.invalidateQueries({ queryKey: ['providers'] })
  const create = useMutation({
    mutationFn: (body: Record<string, unknown>) => api('/api/providers', { method: 'POST', body: JSON.stringify(body) }),
    onSuccess: refresh,
  })
  const remove = useMutation({
    mutationFn: (id: number) => api(`/api/providers/${id}`, { method: 'DELETE' }),
    onSuccess: refresh,
  })
  const [name, setName] = useState('')
  const [driver, setDriver] = useState('openai')
  const [baseUrl, setBaseUrl] = useState('')

  function submit(event: FormEvent) {
    event.preventDefault()
    create.mutate({ name, driver, base_url: baseUrl || null, enabled: false })
    setName('')
    setBaseUrl('')
  }

  return (
    <section className="grid gap-4">
      <PageHeader title="BYOK" lede="API-Schlüssel bleiben verschlüsselt auf dem Server. Die Oberfläche zeigt nur die letzten vier Zeichen. Kosten ohne vom Anbieter gelieferte Preisdaten bleiben leer und werden nicht erfunden." />
      {!isAdmin ? <Notice>Nur die Verwaltung kann Schlüssel hinterlegen.</Notice> : null}
      <ErrorText error={providers.error ?? create.error ?? remove.error} />
      {isAdmin ? (
        <form className="grid gap-3 rounded-lg border border-line bg-card p-4 md:grid-cols-4" onSubmit={submit}>
          <Field labelText="Name"><input className={controlClass} required value={name} onChange={(event) => setName(event.target.value)} /></Field>
          <Field labelText="Treiber">
            <select className={controlClass} value={driver} onChange={(event) => setDriver(event.target.value)}>
              {DRIVERS.map((item) => <option key={item} value={item}>{item}</option>)}
            </select>
          </Field>
          <Field labelText="Basis-URL"><input className={controlClass} type="url" value={baseUrl} onChange={(event) => setBaseUrl(event.target.value)} placeholder="nur bei eigenem Endpunkt nötig" /></Field>
          <button className="self-end rounded-md bg-forest px-3 py-2 text-paper" type="submit">Provider hinzufügen</button>
        </form>
      ) : null}
      {(providers.data ?? []).map((provider) => (
        <ProviderCard key={provider.id} provider={provider} isAdmin={isAdmin} onChanged={refresh} onDelete={() => remove.mutate(provider.id)} />
      ))}
    </section>
  )
}

function ProviderCard({ provider, isAdmin, onChanged, onDelete }: { provider: Provider; isAdmin: boolean; onChanged: () => void; onDelete: () => void }) {
  const [labelText, setLabelText] = useState('Standard')
  const [secret, setSecret] = useState('')
  const [modelId, setModelId] = useState('')
  const [displayName, setDisplayName] = useState('')
  const [jsonMode, setJsonMode] = useState(false)
  const [priceIn, setPriceIn] = useState('')
  const [priceOut, setPriceOut] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<unknown>(null)

  async function storeKey(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await api(`/api/providers/${provider.id}/credentials`, { method: 'POST', body: JSON.stringify({ label: labelText, secret }) })
      setSecret('')
      setMessage('Schlüssel gespeichert. Er wird nicht wieder angezeigt.')
      onChanged()
    } catch (caught) {
      setError(caught)
    }
  }

  async function test(id: number) {
    setError(null)
    try {
      const result = await api<{ model_count?: number }>(`/api/credentials/${id}/test`, { method: 'POST' })
      setMessage(`Verbindung geprüft. Modelle laut Anbieter: ${result.model_count ?? 'keine Angabe'}.`)
      onChanged()
    } catch (caught) {
      setError(caught)
    }
  }

  async function addModel(event: FormEvent) {
    event.preventDefault()
    setError(null)
    try {
      await api(`/api/providers/${provider.id}/models`, {
        method: 'POST',
        body: JSON.stringify({
          model_id: modelId,
          display_name: displayName,
          supports_json_mode: jsonMode,
          price_input_per_million: priceIn ? Number(priceIn) : null,
          price_output_per_million: priceOut ? Number(priceOut) : null,
          price_source: priceIn || priceOut ? 'manual' : 'unknown',
        }),
      })
      setModelId('')
      setDisplayName('')
      onChanged()
    } catch (caught) {
      setError(caught)
    }
  }

  return (
    <article className="grid gap-3 rounded-lg border border-line bg-card p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-serif text-xl">{provider.name}</h2>
        <p className="text-sm">{provider.driver} · {provider.enabled ? 'aktiv' : 'deaktiviert'} · {provider.is_fallback ? 'Fallback' : 'kein Fallback'}</p>
      </div>
      <p className="text-sm break-all">{provider.base_url}</p>
      <ErrorText error={error} />
      {message ? <Notice>{message}</Notice> : null}
      <ul className="grid gap-2 text-sm">
        {provider.credentials.map((credential) => (
          <li key={credential.id} className="flex flex-wrap items-center justify-between gap-2">
            <span>{credential.label} · {credential.hint} · Test {credential.last_test_status ?? 'offen'}</span>
            {isAdmin ? (
              <span className="flex gap-2">
                <button type="button" className="underline" onClick={() => void test(credential.id)}>Verbindung testen</button>
                <button type="button" className="underline" onClick={() => void api(`/api/credentials/${credential.id}`, { method: 'DELETE' }).then(onChanged)}>Löschen</button>
              </span>
            ) : null}
          </li>
        ))}
        {provider.credentials.length === 0 ? <li>Kein Schlüssel hinterlegt.</li> : null}
      </ul>
      <ul className="text-sm">
        {provider.models.map((model) => (
          <li key={model.id}>{model.display_name} ({model.model_id}) · JSON {model.supports_json_mode ? 'ja' : 'nein'} · Preisquelle {model.price_source}</li>
        ))}
      </ul>
      {isAdmin ? (
        <>
          <form className="grid gap-2 md:grid-cols-3" onSubmit={(event) => void storeKey(event)}>
            <input className={controlClass} aria-label="Bezeichnung des Schlüssels" value={labelText} onChange={(event) => setLabelText(event.target.value)} />
            <input className={controlClass} aria-label="API-Schlüssel" type="password" autoComplete="off" minLength={8} required value={secret} onChange={(event) => setSecret(event.target.value)} />
            <button className="rounded-md border border-line px-3 py-2" type="submit">Schlüssel speichern</button>
          </form>
          <form className="grid gap-2 md:grid-cols-3" onSubmit={(event) => void addModel(event)}>
            <input className={controlClass} aria-label="Modellkennung" required placeholder="Modellkennung" value={modelId} onChange={(event) => setModelId(event.target.value)} />
            <input className={controlClass} aria-label="Anzeigename" required placeholder="Anzeigename" value={displayName} onChange={(event) => setDisplayName(event.target.value)} />
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={jsonMode} onChange={(event) => setJsonMode(event.target.checked)} /> JSON-Modus laut Anbieter</label>
            <input className={controlClass} aria-label="Preis Eingabe" placeholder="Preis Eingabe je Mio." value={priceIn} onChange={(event) => setPriceIn(event.target.value)} />
            <input className={controlClass} aria-label="Preis Ausgabe" placeholder="Preis Ausgabe je Mio." value={priceOut} onChange={(event) => setPriceOut(event.target.value)} />
            <button className="rounded-md border border-line px-3 py-2" type="submit">Modell speichern</button>
          </form>
          <div className="flex gap-3 text-sm">
            <button type="button" className="underline" onClick={() => void api(`/api/providers/${provider.id}`, { method: 'PATCH', body: JSON.stringify({ enabled: !provider.enabled }) }).then(onChanged)}>
              {provider.enabled ? 'Deaktivieren' : 'Aktivieren'}
            </button>
            <button type="button" className="underline" onClick={() => void api(`/api/providers/${provider.id}`, { method: 'PATCH', body: JSON.stringify({ is_fallback: !provider.is_fallback }) }).then(onChanged)}>
              Fallback {provider.is_fallback ? 'entfernen' : 'setzen'}
            </button>
            <button type="button" className="text-alert underline" onClick={onDelete}>Provider entfernen</button>
          </div>
        </>
      ) : null}
    </article>
  )
}
