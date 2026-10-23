import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, getToken, setToken } from './api'
import type { Role, User } from './types'

type AuthValue = {
  user: User | null
  ready: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  canWrite: boolean
  canReview: boolean
  isAdmin: boolean
}

const AuthContext = createContext<AuthValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [ready, setReady] = useState(false)

  useEffect(() => {
    if (!getToken()) {
      setReady(true)
      return
    }
    api<{ user: User }>('/api/me')
      .then((result) => setUser(result.user))
      .catch(() => setUser(null))
      .finally(() => setReady(true))
  }, [])

  const value = useMemo<AuthValue>(() => {
    const role: Role | undefined = user?.role
    return {
      user,
      ready,
      canWrite: role === 'admin' || role === 'analyst',
      canReview: role === 'admin' || role === 'reviewer',
      isAdmin: role === 'admin',
      async login(email, password) {
        const result = await api<{ token: string; user: User }>('/api/login', {
          method: 'POST',
          body: JSON.stringify({ email, password }),
        })
        setToken(result.token)
        setUser(result.user)
      },
      async logout() {
        await api('/api/logout', { method: 'POST' }).catch(() => undefined)
        setToken(null)
        setUser(null)
      },
    }
  }, [ready, user])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthValue {
  const value = useContext(AuthContext)
  if (!value) throw new Error('AuthProvider fehlt.')
  return value
}
