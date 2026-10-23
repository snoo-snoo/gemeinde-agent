import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorText, Notice, PageHeader, Status, controlClass } from '../components/ui'
import { useMunicipality } from '../municipality'
import type { AgentConfig, AgentRun, Provider } from '../types'

export function AgentsPage() {
  const { isAdmin } = useAuth()
  const { selectedId } = useMunicipality()
  const client = useQueryClient()
  const agents = useQuery({ queryKey: ['agents'], queryFn: () => api<AgentConfig[]>('/api/agents') })
  const runs = useQuery({ queryKey: ['agent-runs'], queryFn: () => api<AgentRun[]>('/api/agent-runs') })
  const providers = useQuery({ queryKey: ['providers'], queryFn: () => api<Provider[]>('/api/providers') })
  const save = useMutation({
    mutationFn: (payload: { id: number; body: Record<string, unknown> }) => api(`/api/agents/${payload.id}`, {
      method: 'PATCH',
      body: JSON.stringify(payload.body),
    }),
    onSuccess: () => client.invalidateQueries({ queryKey: ['agents'] }),
  })
  const narrate = useMutation({
    mutationFn: (id: number) => api(`/api/agents/${id}/narrate`, {
      method: 'POST',
      body: JSON.stringify({ text: 'Formuliere den vorhandenen Prüfhinweis neu, ohne neue Beträge.', allowed_amounts: [] }),
    }),
  })

  return (
    <section className="grid gap-6">
      <PageHeader title="Agenten" lede="Jeder Agent hat eine eigene Konfiguration. Deterministische Agenten rechnen ohne Sprachmodell. Ein Modell wird nur verwendet, wenn Provider, Schlüssel und Modell zugewiesen sind." />
      <ErrorText error={agents.error ?? runs.error ?? save.error ?? narrate.error} />
      <p className="text-sm">Gemeindekontext für den nächsten Lauf: {selectedId ?? 'keine Auswahl'}.</p>
      <div className="grid gap-3">
        {(agents.data ?? []).map((agent) => (
          <AgentCard key={agent.id} agent={agent} providers={providers.data ?? []} isAdmin={isAdmin} pending={save.isPending} onSave={(body) => save.mutate({ id: agent.id, body })} onNarrate={agent.allows_llm && isAdmin ? () => narrate.mutate(agent.id) : undefined} />
        ))}
      </div>
      {narrate.data ? <Notice>Der Sprachmodellaufruf wurde beantwortet. Neue Beträge, die nicht in der erlaubten Liste stehen, werden verworfen.</Notice> : null}
      <article className="table-wrap rounded-lg border border-line bg-card">
        <h2 className="px-3 pt-3 font-serif text-xl">Letzte Läufe</h2>
        <table className="w-full min-w-[48rem] text-left text-sm">
          <caption className="sr-only">Agentenläufe</caption>
          <thead>
            <tr>
              <th className="px-3 py-2">Agent</th>
              <th className="px-3 py-2">Status</th>
              <th className="px-3 py-2">Tokens</th>
              <th className="px-3 py-2">Kosten</th>
              <th className="px-3 py-2">Fehler</th>
            </tr>
          </thead>
          <tbody>
            {(runs.data ?? []).map((run) => (
              <tr key={run.id} className="border-t border-line">
                <td className="px-3 py-2">{run.config?.name ?? run.id}</td>
                <td className="px-3 py-2"><Status value={run.status} /></td>
                <td className="px-3 py-2">{run.input_tokens ?? '–'} / {run.output_tokens ?? '–'}</td>
                <td className="px-3 py-2">{run.cost_cents === null ? 'keine Preisdaten' : `${run.cost_cents} Cent${run.cost_estimated ? ' (geschätzt)' : ''}`}</td>
                <td className="px-3 py-2">{run.error ?? '–'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </article>
    </section>
  )
}

function AgentCard({ agent, providers, isAdmin, pending, onSave, onNarrate }: {
  agent: AgentConfig
  providers: Provider[]
  isAdmin: boolean
  pending: boolean
  onSave: (body: Record<string, unknown>) => void
  onNarrate?: () => void
}) {
  const [providerId, setProviderId] = useState(agent.provider_id ? String(agent.provider_id) : '')
  const [modelId, setModelId] = useState(agent.model_config_id ? String(agent.model_config_id) : '')
  const [enabled, setEnabled] = useState(agent.enabled)
  const [tokenBudget, setTokenBudget] = useState(agent.token_budget ? String(agent.token_budget) : '')
  const [costBudget, setCostBudget] = useState(agent.cost_budget_cents ? String(agent.cost_budget_cents) : '')
  const models = providers.find((provider) => String(provider.id) === providerId)?.models ?? []
  return (
    <article className="rounded-lg border border-line bg-card p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="font-serif text-xl">{agent.name}</h2>
          <p className="mt-1 text-sm">{agent.task}</p>
          <p className="mt-1 text-sm text-ink/70">Promptversion {agent.prompt_version} · Timeout {agent.timeout_seconds}s · {agent.allows_llm ? 'Sprachmodell erlaubt' : 'nur deterministisch'}</p>
        </div>
        <span className="text-sm">{enabled ? 'Aktiv' : 'Inaktiv'}</span>
      </div>
      {isAdmin ? (
        <form className="mt-3 grid gap-3 md:grid-cols-4" onSubmit={(event) => {
          event.preventDefault()
          onSave({
            enabled,
            provider_id: providerId ? Number(providerId) : null,
            model_config_id: modelId ? Number(modelId) : null,
            token_budget: tokenBudget ? Number(tokenBudget) : null,
            cost_budget_cents: costBudget ? Number(costBudget) : null,
          })
        }}>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={enabled} onChange={(event) => setEnabled(event.target.checked)} /> Aktiv</label>
          <select className={controlClass} value={providerId} onChange={(event) => { setProviderId(event.target.value); setModelId('') }} aria-label="Provider">
            <option value="">Kein Provider</option>
            {providers.map((provider) => <option key={provider.id} value={provider.id}>{provider.name}</option>)}
          </select>
          <select className={controlClass} value={modelId} onChange={(event) => setModelId(event.target.value)} aria-label="Modell">
            <option value="">Kein Modell</option>
            {models.map((model) => <option key={model.id} value={model.id}>{model.display_name}</option>)}
          </select>
          <input className={controlClass} inputMode="numeric" placeholder="Tokenbudget" aria-label="Tokenbudget" value={tokenBudget} onChange={(event) => setTokenBudget(event.target.value)} />
          <input className={controlClass} inputMode="numeric" placeholder="Kostenbudget in Cent" aria-label="Kostenbudget" value={costBudget} onChange={(event) => setCostBudget(event.target.value)} />
          <button className="rounded-md bg-forest px-3 py-2 text-paper" type="submit" disabled={pending}>Speichern</button>
          {onNarrate ? <button className="rounded-md border border-line px-3 py-2" type="button" onClick={onNarrate}>Verbindung der Erzählung prüfen</button> : null}
        </form>
      ) : null}
    </article>
  )
}
