import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { api } from '../api'
import { ErrorText, Field, Notice, PageHeader, controlClass } from '../components/ui'
import { euro, label } from '../format'
import { useMunicipality } from '../municipality'
import type { Fact, Validation } from '../types'

const CODES = 'SA0,SA1,SA5,SA7,21,22,31,34,35'

export function AnalysisPage() {
  const { selectedId } = useMunicipality()
  const [code, setCode] = useState('SA0')
  const [scope, setScope] = useState('bereinigt')
  const facts = useQuery({
    queryKey: ['facts', selectedId, scope],
    enabled: selectedId !== null,
    queryFn: () => api<Fact[]>(`/api/facts?municipality_id=${selectedId}&scope=${scope}&codes=${CODES}`),
  })
  const validations = useQuery({
    queryKey: ['validations'],
    queryFn: () => api<Validation[]>('/api/validations'),
  })
  const rows = useMemo(() => (facts.data ?? []).filter((fact) => fact.code === code), [code, facts.data])
  const chart = rows
    .filter((fact) => fact.value_kind === 'ist' && fact.fiscal_year)
    .map((fact) => ({ year: fact.fiscal_year, betrag: Number(fact.amount) }))
    .sort((left, right) => (left.year ?? 0) - (right.year ?? 0))

  return (
    <section className="grid gap-4">
      <PageHeader title="Finanzanalyse" lede="Plan, Ist und Differenz werden aus den gedruckten Spalten gelesen. Prozentwerte entstehen nur bei einem positiven Planwert und werden nicht von einem Sprachmodell neu gerechnet." />
      <div className="grid gap-3 sm:grid-cols-2">
        <Field labelText="Position">
          <select className={controlClass} value={code} onChange={(event) => setCode(event.target.value)}>
            {CODES.split(',').map((item) => <option key={item} value={item}>{item}</option>)}
          </select>
        </Field>
        <Field labelText="Umfang">
          <select className={controlClass} value={scope} onChange={(event) => setScope(event.target.value)}>
            <option value="bereinigt">Bereinigt um interne Vergütungen</option>
            <option value="inklusive_interne">Interne Vergütungen enthalten</option>
            <option value="nachweis">Nachweis</option>
            <option value="liquiditaet">Liquidität</option>
          </select>
        </Field>
      </div>
      <ErrorText error={facts.error ?? validations.error} />
      {chart.length > 0 ? (
        <article className="h-72 rounded-lg border border-line bg-card p-4">
          <h2 className="mb-2 font-serif text-xl">Istwerte {code}</h2>
          <ResponsiveContainer width="100%" height="85%">
            <LineChart data={chart}>
              <XAxis dataKey="year" />
              <YAxis tickFormatter={(value: number) => new Intl.NumberFormat('de-AT', { notation: 'compact' }).format(value)} />
              <Tooltip formatter={(value) => euro(Number(value ?? 0))} />
              <Line dataKey="betrag" name="Ist" stroke="#1f4d3a" strokeWidth={2} />
            </LineChart>
          </ResponsiveContainer>
        </article>
      ) : <Notice>Für diese Auswahl liegt kein Istwert vor.</Notice>}
      <div className="table-wrap rounded-lg border border-line bg-card">
        <table className="w-full min-w-[40rem] text-left text-sm">
          <caption className="sr-only">Extrahierte Beträge</caption>
          <thead className="bg-paper">
            <tr>
              <th className="px-3 py-2">Jahr</th>
              <th className="px-3 py-2">Art</th>
              <th className="px-3 py-2">Bezeichnung</th>
              <th className="px-3 py-2">Original</th>
              <th className="px-3 py-2">Seite</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((fact) => (
              <tr key={`${fact.id}-${fact.value_kind}-${fact.fiscal_year}`} className="border-t border-line">
                <td className="px-3 py-2">{fact.fiscal_year ?? '–'}</td>
                <td className="px-3 py-2">{label(fact.value_kind)}</td>
                <td className="px-3 py-2">{fact.original_label}</td>
                <td className="px-3 py-2">{fact.amount_original}</td>
                <td className="px-3 py-2">{fact.page ?? '–'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <article className="rounded-lg border border-line bg-card p-4">
        <h2 className="font-serif text-xl">Letzte Validierungen</h2>
        <ul className="mt-3 grid gap-2 text-sm">
          {(validations.data ?? []).slice(0, 12).map((item) => (
            <li key={item.id}>
              <span className="font-semibold">{item.rule_code}</span> · {label(item.status)} · {item.message}
            </li>
          ))}
          {(validations.data ?? []).length === 0 ? <li>Noch keine Prüfung.</li> : null}
        </ul>
      </article>
    </section>
  )
}
