import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../api'
import { useAuth } from '../auth'
import { ErrorText, Notice, PageHeader, Status, controlClass } from '../components/ui'
import { label } from '../format'
import { useMunicipality } from '../municipality'
import type { Question } from '../types'

export function QuestionsPage() {
  const { selectedId } = useMunicipality()
  const { canWrite } = useAuth()
  const client = useQueryClient()
  const [status, setStatus] = useState('')
  const questions = useQuery({
    queryKey: ['questions', selectedId, status],
    enabled: selectedId !== null,
    queryFn: () => {
      const params = new URLSearchParams({ municipality_id: String(selectedId) })
      if (status) params.set('status', status)
      return api<Question[]>(`/api/questions?${params}`)
    },
  })
  const answer = useMutation({
    mutationFn: (payload: { id: number; answer: string }) => api(`/api/questions/${payload.id}/answer`, {
      method: 'POST',
      body: JSON.stringify({ answer: payload.answer, status: 'answered' }),
    }),
    onSuccess: () => client.invalidateQueries({ queryKey: ['questions', selectedId] }),
  })

  return (
    <section className="grid gap-4">
      <PageHeader title="Fragenkatalog" lede="Fragen verwenden Beträge aus der konkreten Analyse. Sie richten sich an Bürgermeister, Gemeinderat, Finanzverwaltung oder Prüfungsausschuss." />
      <label className="grid max-w-xs gap-1 text-sm">
        Status
        <select className={controlClass} value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">Alle</option>
          <option value="open">Offen</option>
          <option value="answered">Beantwortet</option>
          <option value="closed">Erledigt</option>
        </select>
      </label>
      <ErrorText error={questions.error ?? answer.error} />
      {(questions.data ?? []).length === 0 && !questions.isLoading ? <Notice>Keine Fragen für diese Auswahl.</Notice> : null}
      {(questions.data ?? []).map((question) => (
        <article key={question.id} className="grid gap-2 rounded-lg border border-line bg-card p-4">
          <div className="flex flex-wrap justify-between gap-2">
            <p className="text-sm">{label(question.audience)} · {question.topic}</p>
            <Status value={question.status} />
          </div>
          <p>{question.body}</p>
          {question.answer ? <p className="text-sm"><span className="font-semibold">Stellungnahme: </span>{question.answer}</p> : null}
          {canWrite && question.status === 'open' ? <AnswerForm onSubmit={(text) => answer.mutate({ id: question.id, answer: text })} /> : null}
        </article>
      ))}
    </section>
  )
}

function AnswerForm({ onSubmit }: { onSubmit: (answer: string) => void }) {
  const [text, setText] = useState('')
  return (
    <form className="grid gap-2" onSubmit={(event) => { event.preventDefault(); onSubmit(text); setText('') }}>
      <label className="grid gap-1 text-sm">
        Stellungnahme
        <textarea className={controlClass} required value={text} onChange={(event) => setText(event.target.value)} />
      </label>
      <button className="w-fit rounded-md bg-forest px-3 py-2 text-paper" type="submit">Antwort speichern</button>
    </form>
  )
}
