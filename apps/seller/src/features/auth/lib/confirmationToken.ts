const CONFIRMATION_PATH = '/confirm-email' as const

interface ConfirmationHistory {
  readonly state: unknown
  replaceState(data: unknown, unused: string, url?: string | URL | null): void
}

export interface TakenConfirmationToken {
  token: string | null
  sanitizedPath: typeof CONFIRMATION_PATH
}

export function takeConfirmationToken(href: string): TakenConfirmationToken {
  const value = new URL(href).searchParams.get('token')

  return {
    token: value !== null && value.trim().length > 0 ? value : null,
    sanitizedPath: CONFIRMATION_PATH,
  }
}

export function eraseConfirmationAddress(
  history: ConfirmationHistory,
  sanitizedPath: typeof CONFIRMATION_PATH,
): void {
  history.replaceState(history.state, '', sanitizedPath)
}
