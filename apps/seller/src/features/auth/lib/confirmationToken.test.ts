import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  bootstrapBrowserConfirmationToken,
  consumeBootstrappedConfirmationToken,
  eraseConfirmationAddress,
  takeBrowserConfirmationToken,
  takeConfirmationToken,
} from './confirmationToken'

const VALID_TOKEN = '0123456789abcdef'.repeat(4)

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('confirmation token hygiene', () => {
  it('returns a non-blank token only in the in-memory result', () => {
    expect(
      takeConfirmationToken(
        `https://app.example.test/confirm-email#token=${VALID_TOKEN}`,
      ),
    ).toEqual({ token: VALID_TOKEN, sanitizedPath: '/confirm-email' })
  })

  it.each([
    'https://app.example.test/confirm-email',
    'https://app.example.test/confirm-email#token=',
    'https://app.example.test/confirm-email#token=short',
    `https://app.example.test/confirm-email#token=${'g'.repeat(64)}`,
    'https://app.example.test/confirm-email#token=%ZZ',
  ])('rejects a missing or malformed fragment token locally: %s', (href) => {
    expect(takeConfirmationToken(href)).toEqual({
      token: null,
      sanitizedPath: '/confirm-email',
    })
  })

  it('never accepts a bearer token from the query string', () => {
    expect(
      takeConfirmationToken(
        `https://app.example.test/confirm-email?token=${VALID_TOKEN}`,
      ),
    ).toEqual({ token: null, sanitizedPath: '/confirm-email' })
  })

  it('does not rewrite an unrelated route that happens to have a fragment', () => {
    const replaceState = vi.fn()

    expect(
      takeBrowserConfirmationToken(
        { href: 'https://app.example.test/companies#sales' },
        { state: null, replaceState },
      ),
    ).toEqual({ token: null, sanitizedPath: '/confirm-email' })
    expect(replaceState).not.toHaveBeenCalled()
  })

  it('extracts the fragment and erases the complete address synchronously', () => {
    const state = { source: 'mail' }
    const replaceState = vi.fn()
    const log = vi.spyOn(console, 'log').mockImplementation(() => undefined)
    const localSetItem = vi.fn()
    const sessionSetItem = vi.fn()
    vi.stubGlobal('localStorage', { setItem: localSetItem })
    vi.stubGlobal('sessionStorage', { setItem: sessionSetItem })
    const taken = takeBrowserConfirmationToken(
      {
        href: `https://app.example.test/confirm-email?utm_source=mail#token=${VALID_TOKEN}`,
      },
      { state, replaceState },
    )

    expect(taken).toEqual({
      token: VALID_TOKEN,
      sanitizedPath: '/confirm-email',
    })
    expect(replaceState).toHaveBeenCalledOnce()
    expect(replaceState).toHaveBeenCalledWith(state, '', '/confirm-email')
    expect(JSON.stringify(replaceState.mock.calls)).not.toContain(VALID_TOKEN)
    expect(localSetItem).not.toHaveBeenCalled()
    expect(sessionSetItem).not.toHaveBeenCalled()
    expect(log).not.toHaveBeenCalled()
  })

  it('hands the bootstrap token to the confirmation page exactly once', () => {
    const replaceState = vi.fn()

    bootstrapBrowserConfirmationToken(
      {
        href: `https://app.example.test/confirm-email#token=${VALID_TOKEN}`,
      },
      { state: null, replaceState },
    )

    expect(consumeBootstrappedConfirmationToken()).toBe(VALID_TOKEN)
    expect(consumeBootstrappedConfirmationToken()).toBeNull()
  })

  it('keeps the address eraser independent from token contents', () => {
    const state = { source: 'mail' }
    const replaceState = vi.fn()

    eraseConfirmationAddress({ state, replaceState }, '/confirm-email')

    expect(replaceState).toHaveBeenCalledWith(state, '', '/confirm-email')
  })
})
