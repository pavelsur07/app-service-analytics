export const CAPTCHA_LOADER_TIMEOUT_MS = 10_000

export function scheduleCaptchaLoaderTimeout(
  onTimeout: () => void,
): () => void {
  const timeout = globalThis.setTimeout(onTimeout, CAPTCHA_LOADER_TIMEOUT_MS)

  return () => {
    globalThis.clearTimeout(timeout)
  }
}
