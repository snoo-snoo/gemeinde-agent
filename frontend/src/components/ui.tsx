import type { ReactNode } from 'react'
import { label } from '../format'

export function PageHeader({ title, lede, children }: { title: string; lede?: string; children?: ReactNode }) {
  return (
    <header className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="font-serif text-3xl text-forest">{title}</h1>
        {lede ? <p className="mt-2 max-w-3xl text-ink/80">{lede}</p> : null}
      </div>
      {children}
    </header>
  )
}

export function Notice({ children }: { children: ReactNode }) {
  return <p className="rounded-md border border-line bg-card px-4 py-3 text-sm" role="status">{children}</p>
}

export function ErrorText({ error }: { error: unknown }) {
  if (!error) return null
  const message = error instanceof Error ? error.message : 'Unbekannter Fehler.'
  return <p className="rounded-md border border-alert/40 bg-white px-4 py-3 text-sm text-alert" role="alert">{message}</p>
}

export function Status({ value }: { value: string | null | undefined }) {
  return <span className="inline-block rounded-full bg-forest/10 px-2 py-0.5 text-sm text-forest">{label(value)}</span>
}

export function Field({ labelText, children }: { labelText: string; children: ReactNode }) {
  return (
    <label className="grid gap-1 text-sm">
      <span className="font-semibold">{labelText}</span>
      {children}
    </label>
  )
}

export const controlClass = 'rounded-md border border-line bg-white px-3 py-2 text-ink'

export function MarkdownView({ text }: { text: string }) {
  return <pre className="whitespace-pre-wrap font-sans text-sm leading-6">{text}</pre>
}
