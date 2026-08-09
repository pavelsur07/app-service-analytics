import { describe, expect, it } from 'vitest'
import { parseApiError } from './ApiError'

describe('parseApiError', () => {
  it('reads status, code and message from the backend error body', async () => {
    const response = new Response(
      JSON.stringify({
        status: 422,
        code: 'invalid_limit',
        message: 'limit must be between 1 and 200.',
      }),
      { status: 422 },
    )

    const error = await parseApiError(response)

    expect(error.status).toBe(422)
    expect(error.code).toBe('invalid_limit')
    expect(error.message).toBe('limit must be between 1 and 200.')
  })

  it('falls back to a generic message when the body is not the expected shape', async () => {
    const response = new Response('<html>502 Bad Gateway</html>', {
      status: 502,
    })

    const error = await parseApiError(response)

    expect(error.status).toBe(502)
    expect(error.code).toBeNull()
    expect(error.message).toBe('HTTP 502')
  })

  it('falls back to a generic message when the body is empty', async () => {
    const response = new Response('', { status: 500 })

    const error = await parseApiError(response)

    expect(error.status).toBe(500)
    expect(error.code).toBeNull()
  })
})
