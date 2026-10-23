import { NavLink, Outlet } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api'
import { useAuth } from '../auth'
import type { Municipality } from '../types'
import { useMunicipality } from '../municipality'

const links = [
  ['/', 'Übersicht'],
  ['/dokumente', 'Archiv'],
  ['/analyse', 'Analyse'],
  ['/befunde', 'Auffälligkeiten'],
  ['/fragen', 'Fragen'],
  ['/berichte', 'Berichte'],
  ['/agenten', 'Agenten'],
  ['/anbieter', 'BYOK'],
  ['/einstellungen', 'Einstellungen'],
] as const

export function Shell() {
  const { user, logout } = useAuth()
  const { selectedId, setSelectedId } = useMunicipality()
  const municipalities = useQuery({
    queryKey: ['municipalities'],
    queryFn: () => api<Municipality[]>('/api/municipalities'),
  })

  return (
    <div className="min-h-screen">
      <a href="#inhalt" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-10 focus:bg-white focus:px-3 focus:py-2">
        Zum Inhalt
      </a>
      <header className="border-b border-line bg-forest text-paper">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-4">
          <div>
            <p className="font-serif text-2xl">KommunalAudit AI</p>
            <p className="text-sm text-paper/80">Prüfung veröffentlichter Gemeindefinanzen</p>
          </div>
          <div className="flex flex-wrap items-center gap-3 text-sm">
            <label className="grid gap-1">
              <span className="text-paper/80">Gemeinde</span>
              <select
                className="rounded-md bg-white px-2 py-1 text-ink"
                value={selectedId ?? ''}
                onChange={(event) => setSelectedId(Number(event.target.value))}
              >
                {(municipalities.data ?? []).map((item) => (
                  <option key={item.id} value={item.id}>{item.name}</option>
                ))}
              </select>
            </label>
            <div>
              <p>{user?.name}</p>
              <p className="text-paper/80">{user?.role}</p>
            </div>
            <button type="button" className="rounded-md border border-paper/40 px-3 py-2" onClick={() => void logout()}>
              Abmelden
            </button>
          </div>
        </div>
        <nav aria-label="Bereiche" className="mx-auto flex max-w-7xl gap-1 overflow-x-auto px-2 pb-2">
          {links.map(([to, text]) => (
            <NavLink
              key={to}
              to={to}
              end={to === '/'}
              className={({ isActive }) => `rounded-md px-3 py-2 text-sm whitespace-nowrap ${isActive ? 'bg-paper text-forest' : 'text-paper hover:bg-white/10'}`}
            >
              {text}
            </NavLink>
          ))}
        </nav>
      </header>
      <main id="inhalt" className="mx-auto max-w-7xl px-4 py-6">
        <Outlet />
      </main>
    </div>
  )
}
