import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, downloadFile } from '../api'
import { ErrorText, Field, Notice, PageHeader, Status, controlClass } from '../components/ui'
import { bytes, label, when } from '../format'
import { useMunicipality } from '../municipality'
import type { ArchiveDocument, Page } from '../types'

export function DocumentsPage() {
  const { selectedId } = useMunicipality()
  const [documentType, setDocumentType] = useState('')
  const [year, setYear] = useState('')
  const [q, setQ] = useState('')
  const [error, setError] = useState<unknown>(null)
  const documents = useQuery({
    queryKey: ['documents', selectedId, documentType, year, q],
    enabled: selectedId !== null,
    queryFn: () => {
      const params = new URLSearchParams({ municipality_id: String(selectedId) })
      if (documentType) params.set('document_type', documentType)
      if (year) params.set('fiscal_year', year)
      if (q) params.set('q', q)
      return api<Page<ArchiveDocument>>(`/api/documents?${params}`)
    },
  })

  return (
    <section className="grid gap-4">
      <PageHeader title="Dokumentenarchiv" lede="Originaldateien bleiben von den extrahierten Zahlen getrennt. Ein Eintrag ohne Version wurde entdeckt, aber in diesem Lauf nicht heruntergeladen." />
      <form className="grid gap-3 sm:grid-cols-3" onSubmit={(event) => event.preventDefault()}>
        <Field labelText="Suche">
          <input className={controlClass} value={q} onChange={(event) => setQ(event.target.value)} />
        </Field>
        <Field labelText="Dokumenttyp">
          <select className={controlClass} value={documentType} onChange={(event) => setDocumentType(event.target.value)}>
            <option value="">Alle</option>
            {['rechnungsabschluss', 'voranschlag', 'nachtragsvoranschlag', 'finanzplan', 'pruefbericht', 'protokoll'].map((type) => (
              <option key={type} value={type}>{label(type)}</option>
            ))}
          </select>
        </Field>
        <Field labelText="Haushaltsjahr">
          <input className={controlClass} inputMode="numeric" value={year} onChange={(event) => setYear(event.target.value)} />
        </Field>
      </form>
      <ErrorText error={documents.error ?? error} />
      {documents.isLoading ? <Notice>Archiv wird geladen.</Notice> : null}
      <ArchiveTable rows={documents.data?.data ?? []} onError={setError} />
      <p className="text-sm">{documents.data?.total ?? documents.data?.meta?.total ?? 0} Einträge</p>
    </section>
  )
}

function ArchiveTable({ rows, onError }: { rows: ArchiveDocument[]; onError: (error: unknown) => void }) {
  return (
      <div className="table-wrap rounded-lg border border-line bg-card">
        <table className="w-full min-w-[48rem] text-left text-sm">
          <caption className="sr-only">Gefundene Dokumente</caption>
          <thead className="bg-paper">
            <tr>
              <th className="px-3 py-2">Titel</th>
              <th className="px-3 py-2">Typ</th>
              <th className="px-3 py-2">Jahr</th>
              <th className="px-3 py-2">Status</th>
              <th className="px-3 py-2">Version</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((document) => {
              const version = [...(document.versions ?? [])].sort((left, right) => right.version_number - left.version_number)[0]
              return (
                <tr key={document.id} className="border-t border-line align-top">
                  <td className="px-3 py-2">
                    <a className="underline" href={document.canonical_url}>{document.title}</a>
                  </td>
                  <td className="px-3 py-2">{label(document.document_type)}</td>
                  <td className="px-3 py-2">{document.fiscal_year ?? '–'}</td>
                  <td className="px-3 py-2"><Status value={document.status} /></td>
                  <td className="px-3 py-2">
                    {version ? (
                      <div className="grid gap-1">
                        <span>SHA-256 {version.sha256.slice(0, 12)}…</span>
                        <span>{bytes(version.byte_size)} · {when(version.fetched_at)}</span>
                        <button
                          type="button"
                          className="w-fit underline"
                          onClick={() => {
                            onError(null)
                            downloadFile(`/api/documents/${document.id}/file`, `${document.title}.pdf`).catch(onError)
                          }}
                        >
                          Originaldatei laden
                        </button>
                      </div>
                    ) : 'Keine Datei archiviert'}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
  )
}
