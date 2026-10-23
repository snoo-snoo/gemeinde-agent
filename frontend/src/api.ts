const TOKEN_KEY = 'kommunalaudit.token'

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token: string | null): void {
  if (token) localStorage.setItem(TOKEN_KEY, token)
  else localStorage.removeItem(TOKEN_KEY)
}

async function errorMessage(response: Response): Promise<string> {
  const body = await response.json().catch(() => null) as { message?: string; error?: string; errors?: Record<string, string[]> } | null
  const first = body?.errors ? Object.values(body.errors).flat()[0] : undefined
  return first || body?.message || body?.error || `Anfrage fehlgeschlagen (${response.status}).`
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body && !(init.body instanceof FormData) && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }
  const token = getToken()
  if (token) headers.set('Authorization', `Bearer ${token}`)
  const response = await fetch(path, { ...init, headers })
  if (response.status === 401) {
    setToken(null)
    throw new Error('Die Anmeldung ist abgelaufen.')
  }
  if (!response.ok) throw new Error(await errorMessage(response))
  if (response.status === 204) return undefined as T
  const type = response.headers.get('content-type') ?? ''
  if (type.includes('application/json')) return response.json() as Promise<T>
  return response.text() as Promise<T>
}

export async function downloadFile(path: string, filename: string): Promise<void> {
  const headers = new Headers()
  const token = getToken()
  if (token) headers.set('Authorization', `Bearer ${token}`)
  const response = await fetch(path, { headers })
  if (!response.ok) throw new Error(await errorMessage(response))
  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
