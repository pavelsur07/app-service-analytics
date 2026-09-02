import { describe, expect, it } from 'vitest'

import {
  acceptCaptcha,
  failCaptcha,
  resetCaptcha,
  showChallenge,
  startCaptcha,
} from './captchaFlow'

const request = {
  email: 'owner@example.test',
  password: 'password is unchanged',
  companyName: 'Example company',
  legalConsent: true as const,
}

describe('captcha flow', () => {
  it('starts checking from idle and keeps the pending registration payload', () => {
    expect(startCaptcha({ status: 'idle' }, request)).toEqual({
      status: 'checking',
      request,
    })
  })

  it('keeps checking when the provider makes its challenge visible', () => {
    const checking = { status: 'checking' as const, request }

    expect(showChallenge(checking)).toBe(checking)
  })

  it('submits only on the first non-empty success while checking', () => {
    const checking = { status: 'checking' as const, request }

    expect(acceptCaptcha(checking, 'widget-token')).toEqual({
      status: 'submitting',
      request,
    })
    expect(acceptCaptcha(checking, '')).toBe(checking)
  })

  it('ignores a duplicate or stale success outside checking', () => {
    const submitting = { status: 'submitting' as const, request }
    const idle = { status: 'idle' as const }
    const failed = { status: 'failed' as const, message: 'Retry.' }

    expect(acceptCaptcha(submitting, 'widget-token')).toBe(submitting)
    expect(acceptCaptcha(idle, 'widget-token')).toBe(idle)
    expect(acceptCaptcha(failed, 'widget-token')).toBe(failed)
  })

  it('fails provider callbacks only while checking', () => {
    const checking = { status: 'checking' as const, request }
    const submitting = { status: 'submitting' as const, request }

    expect(failCaptcha(checking, 'Captcha is unavailable.')).toEqual({
      status: 'failed',
      message: 'Captcha is unavailable.',
    })
    expect(failCaptcha(submitting, 'Captcha is unavailable.')).toBe(submitting)
  })

  it('resets to idle', () => {
    expect(resetCaptcha()).toEqual({ status: 'idle' })
  })
})
