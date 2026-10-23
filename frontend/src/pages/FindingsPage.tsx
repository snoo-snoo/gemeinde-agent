import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorText, Notice, PageHeader, Status, controlClass } from '../components/ui'
import { euro, percent } from '../format'
import { useMunicipality } from '../municipality'
import type { Finding } from '../types'

export function FindingsPage() {
  const { selectedId } = useMunicipality()
  const { canReview } = useAuth()
  const client = useQueryClient()
  const findings = useQuery({
    queryKey: ['findings', selectedId],
    enabled: selectedId !== null,
    queryFn: () => api<Finding[]>(`/api/findings?municipality_id=${selectedId}`),
  })
  const review = useMutation({
    mutationFn: (payload: { id: number; decision: string; comment: string }) => api(`/api/findings/${payload.id}/review`, {
      method: 'POST',
      body: JSON.stringify({ decision: payload.decision, comment: payload.comment }),
    }),
    onSuccess: () => client.invalidateQueries({ queryKey: ['findings', selectedId] }),
  })

  return (
    <section className="grid gap-4">
      <PageHeader title="Auffälligkeiten" lede="Ein Befund ist ein Prüfhinweis. Er begründet weder Korruption noch ein strafbares Verhalten. Die Freigabe bleibt eine menschliche Entscheidung." />
      <ErrorText error={findings.error ?? review.error} />
      {findings.isLoading ? <Notice>Befunde werden geladen.</Notice> : null}
      {(findings.data ?? []).length === 0 && !findings.isLoading ? <Notice>Es liegen keine belastbaren Befunde vor oder der Lauf hat noch keine erzeugt.</Notice> : null}
      {(findings.data ?? []).map((finding) => (
        <article key={finding.id} className="grid gap-3 rounded-lg border border-line bg-card p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 className="font-serif text-xl">{finding.title}</h2>
            <div className="flex gap-2"><Status value={finding.review_status} /><Status value={finding.approval_status} /></div>
          </div>
          <p>{finding.summary}</p>
          <dl className="grid gap-2 text-sm sm:grid-cols-2">
            <div><dt className="font-semibold">Absolute Abweichung</dt><dd>{euro(finding.absolute_deviation)}</dd></div>
            <div><dt className="font-semibold">Relative Abweichung</dt><dd>{percent(finding.relative_deviation)}</dd></div>
            <div><dt className="font-semibold">Methode</dt><dd>{finding.method ?? '–'}</dd></div>
            <div><dt className="font-semibold">Priorität</dt><dd>{finding.priority}</dd></div>
          </dl>
          {finding.financial_relevance ? <p className="text-sm">{finding.financial_relevance}</p> : null}
          {finding.possible_explanations?.length ? <p className="text-sm">Mögliche Erklärung: {finding.possible_explanations.join(' ')}</p> : null}
          {finding.counterarguments?.length ? <p className="text-sm">Gegenargument: {finding.counterarguments.join(' ')}</p> : null}
          <ul className="grid gap-2 text-sm">
            {finding.evidence.map((item) => (
              <li key={item.id} className="rounded-md bg-paper px-3 py-2">
                {item.source_url ? <a className="underline" href={item.source_url}>Quelle</a> : 'Quelle ohne URL'}
                {item.page ? `, Seite ${item.page}` : ''}
                {item.excerpt ? <span className="mt-1 block">{item.excerpt}</span> : null}
              </li>
            ))}
          </ul>
          {canReview ? <ReviewForm pending={review.isPending} onSubmit={(decision, comment) => review.mutate({ id: finding.id, decision, comment })} /> : null}
        </article>
      ))}
    </section>
  )
}

function ReviewForm({ onSubmit, pending }: { onSubmit: (decision: string, comment: string) => void; pending: boolean }) {
  const [decision, setDecision] = useState('needs_manual_review')
  const [comment, setComment] = useState('')
  return (
    <form className="flex flex-wrap items-end gap-2" onSubmit={(event) => { event.preventDefault(); onSubmit(decision, comment) }}>
      <label className="grid gap-1 text-sm">
        Entscheidung
        <select className={controlClass} value={decision} onChange={(event) => setDecision(event.target.value)}>
          <option value="approved">Freigeben</option>
          <option value="rejected">Zurückweisen</option>
          <option value="needs_manual_review">Manuelle Prüfung</option>
        </select>
      </label>
      <label className="grid min-w-64 flex-1 gap-1 text-sm">
        Kommentar
        <input className={controlClass} value={comment} onChange={(event) => setComment(event.target.value)} />
      </label>
      <button className="rounded-md bg-forest px-3 py-2 text-paper" type="submit" disabled={pending}>Speichern</button>
    </form>
  )
}
