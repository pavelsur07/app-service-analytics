// Токен сброса пароля (ADR-037) приходит во fragment ссылки из письма:
// браузер не отправляет его серверу и не кладёт в Referer. Забирается
// до монтирования роутера и сразу стирается из адреса — по той же схеме,
// что токен подтверждения адреса (confirmationToken.ts).
const RESET_PATH = '/reset-password' as const
const RESET_TOKEN_PATTERN = /^[0-9a-f]{64}$/
let bootstrappedResetToken: string | null = null

interface ResetLocation {
  readonly href: string
}

interface ResetHistory {
  readonly state: unknown
  replaceState(data: unknown, unused: string, url?: string | URL | null): void
}

export function takePasswordResetToken(href: string): string | null {
  const url = new URL(href)
  const value =
    url.pathname === RESET_PATH
      ? new URLSearchParams(url.hash.slice(1)).get('token')
      : null

  return value !== null && RESET_TOKEN_PATTERN.test(value) ? value : null
}

export function bootstrapBrowserPasswordResetToken(
  location: ResetLocation,
  history: ResetHistory,
): void {
  if (new URL(location.href).pathname !== RESET_PATH) {
    return
  }

  bootstrappedResetToken = takePasswordResetToken(location.href)
  history.replaceState(history.state, '', RESET_PATH)
}

export function consumeBootstrappedPasswordResetToken(): string | null {
  const token = bootstrappedResetToken

  bootstrappedResetToken = null

  return token
}
