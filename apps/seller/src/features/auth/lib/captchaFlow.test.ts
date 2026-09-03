import { describe, expect, it } from 'vitest'

import {
  acceptCaptcha,
  failCaptcha,
  failCaptchaLoader,
  resetCaptcha,
  retryCaptcha,
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
    const failed = {
      status: 'failed' as const,
      message: 'Retry.',
      recovery: 'remount' as const,
    }

    expect(acceptCaptcha(submitting, 'widget-token')).toBe(submitting)
    expect(acceptCaptcha(idle, 'widget-token')).toBe(idle)
    expect(acceptCaptcha(failed, 'widget-token')).toBe(failed)
  })

  it('fails provider callbacks only while checking', () => {
    const checking = { status: 'checking' as const, request }
    const submitting = { status: 'submitting' as const, request }
    const idle = { status: 'idle' as const }

    expect(failCaptcha(checking, 'Captcha is unavailable.')).toEqual({
      status: 'failed',
      message: 'Captcha is unavailable.',
      recovery: 'remount',
    })
    expect(failCaptcha(submitting, 'Captcha is unavailable.')).toBe(submitting)
    expect(failCaptcha(idle, 'Late widget failure.')).toBe(idle)
  })

  it('records a pre-submit loader failure and requires a page reload', () => {
    const failed = failCaptchaLoader(
      { status: 'idle' },
      'Captcha loader is unavailable.',
    )

    expect(failed).toEqual({
      status: 'failed',
      message: 'Captcha loader is unavailable.',
      recovery: 'reload-page',
    })
    expect(retryCaptcha(failed)).toEqual({
      action: 'reload-page',
      state: failed,
    })

    expect(
      failCaptchaLoader(
        { status: 'checking', request },
        'Captcha loader timed out.',
      ),
    ).toEqual({
      status: 'failed',
      message: 'Captcha loader timed out.',
      recovery: 'reload-page',
    })
  })

  it('ignores a stale loader callback after submission has settled to idle', () => {
    const idle = resetCaptcha()

    expect(failCaptcha(idle, 'Late widget failure.')).toBe(idle)
  })

  it('starts a fresh widget after a recoverable provider failure', () => {
    const failed = failCaptcha(
      { status: 'checking', request },
      'Captcha is unavailable.',
    )

    expect(retryCaptcha(failed)).toEqual({
      action: 'continue',
      state: { status: 'idle' },
    })
  })

  it('resets to idle', () => {
    expect(resetCaptcha()).toEqual({ status: 'idle' })
  })
})
