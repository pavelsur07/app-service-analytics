import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  eraseConfirmationAddress,
  takeConfirmationToken,
} from './confirmationToken'

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('confirmation token hygiene', () => {
  it('returns a non-blank token only in the in-memory result', () => {
    expect(
      takeConfirmationToken(
        'https://app.example.test/confirm-email?token=one-use-token&utm_source=mail',
      ),
    ).toEqual({ token: 'one-use-token', sanitizedPath: '/confirm-email' })
  })

  it.each([
    'https://app.example.test/confirm-email',
    'https://app.example.test/confirm-email?token=',
    'https://app.example.test/confirm-email?token=%20%20%20',
  ])('rejects a missing or blank token locally: %s', (href) => {
    expect(takeConfirmationToken(href)).toEqual({
      token: null,
      sanitizedPath: '/confirm-email',
    })
  })

  it('erases the complete query without copying the token to history or logs', () => {
    const state = { source: 'mail' }
    const replaceState = vi.fn()
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined)
    const localSetItem = vi.fn()
    const sessionSetItem = vi.fn()
    vi.stubGlobal('localStorage', { setItem: localSetItem })
    vi.stubGlobal('sessionStorage', { setItem: sessionSetItem })
    const taken = takeConfirmationToken(
      'https://app.example.test/confirm-email?token=never-persist-this&utm_source=mail',
    )

    eraseConfirmationAddress({ state, replaceState }, taken.sanitizedPath)

    expect(replaceState).toHaveBeenCalledOnce()
    expect(replaceState).toHaveBeenCalledWith(state, '', '/confirm-email')
    expect(JSON.stringify(replaceState.mock.calls)).not.toContain(
      'never-persist-this',
    )
    expect(localSetItem).not.toHaveBeenCalled()
    expect(sessionSetItem).not.toHaveBeenCalled()
    expect(log).not.toHaveBeenCalled()
  })
})
