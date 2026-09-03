import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  CAPTCHA_LOADER_TIMEOUT_MS,
  scheduleCaptchaLoaderTimeout,
} from './captchaLoaderGuard'

afterEach(() => {
  vi.useRealTimers()
})

describe('captcha loader guard', () => {
  it('fails a pending check when the provider loader never becomes ready', () => {
    vi.useFakeTimers()
    const onTimeout = vi.fn()

    scheduleCaptchaLoaderTimeout(onTimeout)
    vi.advanceTimersByTime(CAPTCHA_LOADER_TIMEOUT_MS - 1)
    expect(onTimeout).not.toHaveBeenCalled()

    vi.advanceTimersByTime(1)
    expect(onTimeout).toHaveBeenCalledOnce()
  })

  it('cancels the failure when the provider becomes ready', () => {
    vi.useFakeTimers()
    const onTimeout = vi.fn()

    const cancel = scheduleCaptchaLoaderTimeout(onTimeout)
    cancel()
    vi.advanceTimersByTime(CAPTCHA_LOADER_TIMEOUT_MS)

    expect(onTimeout).not.toHaveBeenCalled()
  })
})
