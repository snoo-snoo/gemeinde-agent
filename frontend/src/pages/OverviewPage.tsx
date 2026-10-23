import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorText, Notice, PageHeader, Status } from '../components/ui'
import { euro, label, when } from '../format'
import { useMunicipality } from '../municipality'
import type { Overview } from '../types'

export function OverviewPage() {
  const { selectedId } = useMunicipality()
  const { canWrite } = useAuth()
  const client = useQueryClient()
  const [message, setMessage] = useState<string | null>(null)
  const overview = useQuery({
    queryKey: ['overview', selectedId],
    enabled: selectedId !== null,
    queryFn: () => api<Overview>(`/api/municipalities/${selectedId}`),
  })
  const start = useMutation({
    mutationFn: () => api(`/api/analyses`, { method: 'POST', body: JSON.stringify({ municipality_id: selectedId }) }),
    onSuccess: async () => {
      setMessage('Der Prüflauf wurde in die Warteschlange gestellt. Er blockiert diese Seite nicht.')
      await client.invalidateQueries({ queryKey: ['overview', selectedId] })
    },
  })

  const data = overview.data
  const byYear = new Map<number, { year: number; ertraege?: number; aufwendungen?: number; investitionen?: number }>()
  for (const fact of data?.headline ?? []) {
    if (!fact.fiscal_year || fact.value_kind !== 'ist') continue
    const row = byYear.get(fact.fiscal_year) ?? { year: fact.fiscal_year }
    if (fact.code === '21') row.ertraege = Number(fact.amount)
    if (fact.code === '22') row.aufwendungen = Number(fact.amount)
    if (fact.code === '34') row.investitionen = Number(fact.amount)
    byYear.set(fact.fiscal_year, row)
  }
  const chart = [...byYear.values()].sort((left, right) => left.year - right.year)
  const saldo = (data?.headline ?? []).filter((fact) => fact.code === 'SA0' && fact.value_kind === 'ist')

  return (
    <section className="grid gap-6">
      <PageHeader title={data?.municipality.official_name ?? 'Gemeinde'} lede="Kennzahlen stammen aus den gelesenen Veröffentlichungen. Nicht heruntergeladene Dokumente bleiben als Datenlücke sichtbar.">
        {canWrite ? (
          <button type="button" className="rounded-md bg-forest px-4 py-2 text-paper" disabled={!selectedId || start.isPending} onClick={() => start.mutate()}>
            Prüfung starten
          </button>
        ) : null}
      </PageHeader>
      <ErrorText error={overview.error ?? start.error} />
      {message ? <Notice>{message}</Notice> : null}
      {overview.isLoading ? <Notice>Daten werden geladen.</Notice> : null}
      {data ? (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Metric title="Archivierte Dokumente" value={`${data.archived_documents} von ${data.documents}`} />
            <Metric title="Haushaltsjahre" value={data.years.length ? data.years.join(', ') : 'keine'} />
            <Metric title="Auffälligkeiten" value={String(data.findings)} />
            <Metric title="Offene Fragen" value={String(data.open_questions)} />
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <article className="rounded-lg border border-line bg-card p-4">
              <h2 className="font-serif text-xl">Letzter Lauf</h2>
              {data.latest_run ? (
                <dl className="mt-3 grid gap-2 text-sm">
                  <div className="flex justify-between gap-4"><dt>Status</dt><dd><Status value={data.latest_run.status} /></dd></div>
                  <div className="flex justify-between gap-4"><dt>Workflow</dt><dd>{label(data.latest_run.workflow_state)}</dd></div>
                  <div className="flex justify-between gap-4"><dt>Abgeschlossen</dt><dd>{when(data.latest_run.finished_at)}</dd></div>
                  <div className="flex justify-between gap-4"><dt>Fakten</dt><dd>{data.latest_run.summary?.facts ?? '–'}</dd></div>
                  <div className="flex justify-between gap-4"><dt>Heruntergeladen</dt><dd>{data.latest_run.summary?.documents_downloaded ?? '–'} von {data.latest_run.summary?.documents_seen ?? '–'}</dd></div>
                </dl>
              ) : <p className="mt-3 text-sm">Noch kein Prüflauf.</p>}
              {data.latest_run?.error ? <p className="mt-3 text-sm text-alert">{data.latest_run.error}</p> : null}
            </article>
            <article className="rounded-lg border border-line bg-card p-4">
              <h2 className="font-serif text-xl">Nettoergebnis SA0, Ist</h2>
              <ul className="mt-3 grid gap-2 text-sm">
                {saldo.length === 0 ? <li>Kein extrahierter Saldo.</li> : saldo.map((fact) => (
                  <li key={`${fact.fiscal_year}-${fact.amount_original}`}>
                    {fact.fiscal_year}: {fact.amount_original} EUR {fact.page ? `(Seite ${fact.page})` : ''}
                  </li>
                ))}
              </ul>
            </article>
          </div>
          <article className="rounded-lg border border-line bg-card p-4">
            <h2 className="mb-4 font-serif text-xl">Istwerte über die gelesenen Jahre</h2>
            {chart.length === 0 ? <Notice>Für ein Diagramm fehlen noch bereinigte Istwerte der Codes 21, 22 oder 34.</Notice> : (
              <div className="h-80">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={chart}>
                    <CartesianGrid stroke="#d9d1c3" />
                    <XAxis dataKey="year" />
                    <YAxis tickFormatter={(value: number) => new Intl.NumberFormat('de-AT', { notation: 'compact' }).format(value)} />
                    <Tooltip formatter={(value) => euro(Number(value ?? 0))} />
                    <Legend />
                    <Bar dataKey="ertraege" name="Erträge 21" fill="#1f4d3a" />
                    <Bar dataKey="aufwendungen" name="Aufwendungen 22" fill="#8c3a2f" />
                    <Bar dataKey="investitionen" name="Investitionen 34" fill="#c4a574" />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            )}
          </article>
        </>
      ) : null}
    </section>
  )
}

function Metric({ title, value }: { title: string; value: string }) {
  return (
    <article className="rounded-lg border border-line bg-card p-4">
      <h2 className="text-sm text-ink/70">{title}</h2>
      <p className="mt-2 font-serif text-2xl">{value}</p>
    </article>
  )
}
