export function euro(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '–'
  const amount = typeof value === 'number' ? value : Number(value)
  if (Number.isNaN(amount)) return String(value)
  return new Intl.NumberFormat('de-AT', { style: 'currency', currency: 'EUR' }).format(amount)
}

export function percent(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return 'nicht berechnet'
  const amount = Number(value)
  if (Number.isNaN(amount)) return String(value)
  return `${new Intl.NumberFormat('de-AT', { maximumFractionDigits: 2, signDisplay: 'exceptZero' }).format(amount)} %`
}

export function when(value: string | null | undefined): string {
  if (!value) return '–'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat('de-AT', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Europe/Vienna',
  }).format(date)
}

export function bytes(value: number | null | undefined): string {
  if (!value) return '–'
  if (value < 1024) return `${value} B`
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(0)} KB`
  return `${(value / 1024 / 1024).toFixed(1)} MB`
}

const LABELS: Record<string, string> = {
  rechnungsabschluss: 'Rechnungsabschluss',
  voranschlag: 'Voranschlag',
  nachtragsvoranschlag: 'Nachtragsvoranschlag',
  finanzplan: 'Finanzplan',
  pruefbericht: 'Prüfbericht',
  protokoll: 'Protokoll',
  unbekannt: 'Unbekannt',
  archived: 'Archiviert',
  discovered: 'Nur entdeckt',
  failed: 'Fehlgeschlagen',
  completed: 'Abgeschlossen',
  running: 'Läuft',
  pending: 'Wartet',
  cancelled: 'Abgebrochen',
  draft: 'Entwurf',
  approved: 'Freigegeben',
  rejected: 'Zurückgewiesen',
  needs_manual_review: 'Manuelle Prüfung',
  evidence_checked: 'Belege geprüft',
  unreviewed: 'Ungeprüft',
  open: 'Offen',
  answered: 'Beantwortet',
  closed: 'Erledigt',
  ist: 'Ist',
  plan: 'Plan',
  reported_deviation: 'Ausgewiesene Differenz',
  ergebnis: 'Ergebnis',
  finanzierung: 'Finanzierung',
  vermoegen: 'Vermögen',
  nachweis: 'Nachweis',
  liquiditaet: 'Liquidität',
  bereinigt: 'Bereinigt',
  admin: 'Verwaltung',
  analyst: 'Analyse',
  reviewer: 'Prüfung',
  viewer: 'Lesen',
  awaiting_approval: 'Wartet auf Freigabe',
  executive_summary: 'Executive Summary',
  audit_review: 'Prüfungsbericht',
  citizen: 'Bürgerbericht',
  cfo: 'CFO-Bericht',
  questions: 'Fragenkatalog',
  multi_year: 'Mehrjahresvergleich',
  data_quality: 'Datenqualität',
  gaps: 'Datenlücken',
}

export function label(value: string | null | undefined): string {
  if (!value) return '–'
  return LABELS[value] ?? value
}
