const backendUrl = 'http://localhost:8000'

export async function request(path, options = {}) {
  try {
    return await fetch(`${backendUrl}${path}`, {
      ...options,
      credentials: 'include',
      headers: { Accept: 'application/json', ...options.headers },
    })
  } catch {
    throw new Error(`${path}: Network request failed. Check Laravel and CORS settings.`)
  }
}

export async function requireSuccess(response) {
  if (!response.ok) {
    const body = await response.json().catch(() => null)
    const failure = new Error(`HTTP ${response.status}: ${body?.message || response.statusText || 'Request failed'}`)
    failure.status = response.status
    failure.errors = body?.errors || {}
    throw failure
  }
}

export function csrfToken() {
  const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))
  if (!cookie) {
    throw new Error('XSRF-TOKEN cookie is missing. Check cookie settings and use localhost for both servers.')
  }
  return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
}

export async function currentUser() {
  const response = await request('/api/user')
  if (response.status === 401) return null
  await requireSuccess(response)
  return response.json()
}
