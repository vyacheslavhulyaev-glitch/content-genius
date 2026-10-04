const backendUrl = (import.meta.env?.VITE_API_BASE_URL
  ?? (import.meta.env?.PROD ? '' : 'http://localhost:8000')).replace(/\/+$/, '')

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
    failure.code = body?.code
    failure.errors = body?.errors || {}
    failure.retryAfter = Number(body?.retry_after ?? response.headers?.get('Retry-After')) || null
    failure.resetAt = body?.reset_at ?? null
    throw failure
  }
}

export function generationErrorMessage(code) {
  if (code === 'ai_global_budget_exceeded') return 'The AI limit has been reached. Try again in {{minutes}} minute(s). Your existing content has been kept.'
  if (code === 'ai_accounting_unavailable') return 'The AI service is currently unavailable. Your existing text has been kept.'
  if (code === 'generation_rate_limited') return 'AI request limit reached. Try again in {{minutes}} minute(s). Your existing content has been kept.'
  if (code === 'generation_purpose_blocked') return 'Only SEO article requests are supported. Remove instructions that override application rules.'
  return null
}

export function moderationErrorMessage(code) {
  switch (code) {
    case 'moderation_input_blocked':
      return 'Your draft was blocked by moderation. Remove prohibited content and try again.'
    case 'moderation_output_blocked':
      return 'The generated text was blocked by moderation. Your existing text has been kept.'
    case 'moderation_unavailable':
      return 'Moderation is currently unavailable. Your existing text has been kept. Please try again.'
    default:
      return null
  }
}

export function csrfToken() {
  const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))
  if (!cookie) {
    throw new Error('XSRF-TOKEN cookie is missing. Check session cookie and origin settings.')
  }
  return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
}

export async function currentUser() {
  const response = await request('/api/user')
  if (response.status === 401) return null
  await requireSuccess(response)
  return response.json()
}
