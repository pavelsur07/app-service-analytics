const CONFIRMATION_PATH = '/confirm-email' as const
const CONFIRMATION_TOKEN_PATTERN = /^[0-9a-f]{64}$/
// Одноразовая передача из синхронного bootstrap в страницу: после consume
// секрет остаётся только в tokenRef confirmation hook для transient retry.
let bootstrappedConfirmationToken: string | null = null

interface ConfirmationLocation {
  readonly href: string
}

interface ConfirmationHistory {
  readonly state: unknown
  replaceState(data: unknown, unused: string, url?: string | URL | null): void
}

export interface TakenConfirmationToken {
  token: string | null
  sanitizedPath: typeof CONFIRMATION_PATH
}

export function takeConfirmationToken(href: string): TakenConfirmationToken {
  const url = new URL(href)
  const value =
    url.pathname === CONFIRMATION_PATH
      ? new URLSearchParams(url.hash.slice(1)).get('token')
      : null

  return {
    token:
      value !== null && CONFIRMATION_TOKEN_PATTERN.test(value) ? value : null,
    sanitizedPath: CONFIRMATION_PATH,
  }
}

export function takeBrowserConfirmationToken(
  location: ConfirmationLocation,
  history: ConfirmationHistory,
): TakenConfirmationToken {
  const taken = takeConfirmationToken(location.href)

  if (new URL(location.href).pathname === CONFIRMATION_PATH) {
    eraseConfirmationAddress(history, taken.sanitizedPath)
  }

  return taken
}

export function bootstrapBrowserConfirmationToken(
  location: ConfirmationLocation,
  history: ConfirmationHistory,
): void {
  bootstrappedConfirmationToken = takeBrowserConfirmationToken(
    location,
    history,
  ).token
}

export function consumeBootstrappedConfirmationToken(): string | null {
  const token = bootstrappedConfirmationToken

  bootstrappedConfirmationToken = null

  return token
}

export function eraseConfirmationAddress(
  history: ConfirmationHistory,
  sanitizedPath: typeof CONFIRMATION_PATH,
): void {
  history.replaceState(history.state, '', sanitizedPath)
}
