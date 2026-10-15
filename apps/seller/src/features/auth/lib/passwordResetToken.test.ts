import { describe, expect, it, vi } from 'vitest'

import {
  bootstrapBrowserPasswordResetToken,
  consumeBootstrappedPasswordResetToken,
  takePasswordResetToken,
} from './passwordResetToken'

const VALID_TOKEN = '0123456789abcdef'.repeat(4)

describe('password reset token hygiene', () => {
  it('takes a well-formed token from the fragment', () => {
    expect(
      takePasswordResetToken(
        `https://app.example.test/reset-password#token=${VALID_TOKEN}`,
      ),
    ).toBe(VALID_TOKEN)
  })

  it.each([
    'https://app.example.test/reset-password',
    'https://app.example.test/reset-password#token=short',
    `https://app.example.test/reset-password?token=${VALID_TOKEN}`,
    `https://app.example.test/confirm-email#token=${VALID_TOKEN}`,
  ])('rejects a missing, malformed or misplaced token: %s', (href) => {
    expect(takePasswordResetToken(href)).toBeNull()
  })

  it('erases the token from the address and hands it over exactly once', () => {
    const history = { state: { key: 'kept' }, replaceState: vi.fn() }

    bootstrapBrowserPasswordResetToken(
      { href: `https://app.example.test/reset-password#token=${VALID_TOKEN}` },
      history,
    )

    expect(history.replaceState).toHaveBeenCalledWith(
      { key: 'kept' },
      '',
      '/reset-password',
    )
    expect(consumeBootstrappedPasswordResetToken()).toBe(VALID_TOKEN)
    expect(consumeBootstrappedPasswordResetToken()).toBeNull()
  })

  it('leaves unrelated routes untouched', () => {
    const history = { state: null, replaceState: vi.fn() }

    bootstrapBrowserPasswordResetToken(
      { href: 'https://app.example.test/login#section' },
      history,
    )

    expect(history.replaceState).not.toHaveBeenCalled()
    expect(consumeBootstrappedPasswordResetToken()).toBeNull()
  })
})
