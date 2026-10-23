import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from './api'
import type { Municipality } from './types'

type Value = { selectedId: number | null; setSelectedId: (id: number) => void }

const MunicipalityContext = createContext<Value | null>(null)
const KEY = 'kommunalaudit.municipality'

export function MunicipalityProvider({ children }: { children: ReactNode }) {
  const [selectedId, setSelected] = useState<number | null>(() => {
    const stored = localStorage.getItem(KEY)
    return stored ? Number(stored) : null
  })
  const municipalities = useQuery({
    queryKey: ['municipalities'],
    queryFn: () => api<Municipality[]>('/api/municipalities'),
  })

  useEffect(() => {
    if (selectedId || !municipalities.data?.length) return
    setSelected(municipalities.data[0].id)
  }, [municipalities.data, selectedId])

  function setSelectedId(id: number) {
    localStorage.setItem(KEY, String(id))
    setSelected(id)
  }

  return <MunicipalityContext.Provider value={{ selectedId, setSelectedId }}>{children}</MunicipalityContext.Provider>
}

export function useMunicipality(): Value {
  const value = useContext(MunicipalityContext)
  if (!value) throw new Error('MunicipalityProvider fehlt.')
  return value
}
