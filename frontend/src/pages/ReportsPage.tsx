import { Link, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, downloadFile } from '../api'
import { useAuth } from '../auth'
import { ErrorText, MarkdownView, Notice, PageHeader, Status } from '../components/ui'
import { label, when } from '../format'
import { useMunicipality } from '../municipality'
import type { Report } from '../types'

export function ReportsPage() {
  const { selectedId } = useMunicipality()
  const reports = useQuery({
    queryKey: ['reports', selectedId],
    enabled: selectedId !== null,
    queryFn: () => api<Report[]>(`/api/reports?municipality_id=${selectedId}`),
  })
  return (
    <section className="grid gap-4">
      <PageHeader title="Berichte" lede="Alle Perspektiven verwenden dieselbe geprüfte Faktenbasis. Vor der externen Weitergabe bleibt der Status Entwurf, bis eine berechtigte Person freigibt." />
      <ErrorText error={reports.error} />
      <div className="table-wrap rounded-lg border border-line bg-card">
        <table className="w-full min-w-[40rem] text-left text-sm">
          <caption className="sr-only">Berichtsliste</caption>
          <thead className="bg-paper">
            <tr>
              <th className="px-3 py-2">Titel</th>
              <th className="px-3 py-2">Typ</th>
              <th className="px-3 py-2">Zeitraum</th>
              <th className="px-3 py-2">Status</th>
            </tr>
          </thead>
          <tbody>
            {(reports.data ?? []).map((report) => (
              <tr key={report.id} className="border-t border-line">
                <td className="px-3 py-2"><Link className="underline" to={`/berichte/${report.id}`}>{report.title}</Link></td>
                <td className="px-3 py-2">{label(report.report_type)}</td>
                <td className="px-3 py-2">{report.period_start ?? '–'}–{report.period_end ?? '–'}</td>
                <td className="px-3 py-2"><Status value={report.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {(reports.data ?? []).length === 0 && !reports.isLoading ? <Notice>Noch kein Bericht.</Notice> : null}
    </section>
  )
}

export function ReportDetailPage() {
  const { id } = useParams()
  const { canReview } = useAuth()
  const client = useQueryClient()
  const report = useQuery({
    queryKey: ['report', id],
    queryFn: () => api<Report>(`/api/reports/${id}`),
  })
  const approve = useMutation({
    mutationFn: (decision: string) => api(`/api/reports/${id}/approve`, { method: 'POST', body: JSON.stringify({ decision }) }),
    onSuccess: () => client.invalidateQueries({ queryKey: ['report', id] }),
  })
  const data = report.data
  return (
    <section className="grid gap-4">
      <PageHeader title={data?.title ?? 'Bericht'} lede={data ? `Datenstand ${data.data_as_of ?? '–'} · erstellt ${when(data.created_at)}` : undefined} />
      <ErrorText error={report.error ?? approve.error} />
      {data ? (
        <>
          <div className="flex flex-wrap gap-2">
            <Status value={data.status} />
            {(['md', 'pdf', 'docx'] as const).map((format) => (
              <button key={format} type="button" className="rounded-md border border-line bg-white px-3 py-2 text-sm" onClick={() => downloadFile(`/api/reports/${data.id}/export?format=${format}`, `${data.report_type}.${format}`)}>
                {format.toUpperCase()}
              </button>
            ))}
            {canReview && data.status === 'draft' ? (
              <>
                <button type="button" className="rounded-md bg-forest px-3 py-2 text-sm text-paper" onClick={() => approve.mutate('approved')}>Freigeben</button>
                <button type="button" className="rounded-md border border-alert px-3 py-2 text-sm text-alert" onClick={() => approve.mutate('rejected')}>Zurückweisen</button>
              </>
            ) : null}
          </div>
          {data.limitations ? <p className="text-sm">{data.limitations}</p> : null}
          <article className="rounded-lg border border-line bg-card p-4">
            <MarkdownView text={data.body_markdown ?? ''} />
          </article>
        </>
      ) : null}
    </section>
  )
}
