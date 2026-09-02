import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { MemoryRouter } from 'react-router'

import type { ConfirmEmailState } from '../model/useConfirmEmail'
import { ConfirmEmailView } from './ConfirmEmailPage'

function renderState(state: ConfirmEmailState): string {
  return renderToStaticMarkup(
    <MemoryRouter
      initialEntries={[
        '/confirm-email?token=never-render-this&utm_source=mail',
      ]}
    >
      <ConfirmEmailView state={state} onRetry={() => undefined} />
    </MemoryRouter>,
  )
}

describe('confirmation page states', () => {
  it.each([
    [{ kind: 'idle' }, 'Подтверждаем email'],
    [{ kind: 'pending' }, 'Подтверждаем email'],
    [
      {
        kind: 'confirmed',
        action: 'navigate',
        destination: '/onboarding',
      },
      'Email подтверждён',
    ],
  ] as const)('renders progress state %o', (state, expected) => {
    expect(renderState(state)).toContain(expected)
  })

  it('offers login after an already-used link', () => {
    const html = renderState({
      kind: 'already-used',
      action: 'login',
      destination: '/login',
    })

    expect(html).toContain('Ссылка уже использована')
    expect(html).toContain('href="/login"')
  })

  it('offers resend after an expired link', () => {
    const html = renderState({
      kind: 'expired',
      action: 'resend',
      destination: '/resend-confirmation',
    })

    expect(html).toContain('Срок действия ссылки истёк')
    expect(html).toContain('href="/resend-confirmation"')
  })

  it('offers an in-memory retry after a transient failure', () => {
    const html = renderState({ kind: 'transient', action: 'retry' })

    expect(html).toContain('Временная ошибка')
    expect(html).toContain('<button')
    expect(html).toContain('Попробовать ещё раз')
  })

  it('shows a safe invalid-link action without reflecting the route token', () => {
    const html = renderState({ kind: 'missing' })

    expect(html).toContain('Ссылка недействительна')
    expect(html).toContain('href="/resend-confirmation"')
    expect(html).not.toContain('never-render-this')
  })

  it('shows a generic safe failure without backend detail', () => {
    const html = renderState({ kind: 'failure', action: 'none' })

    expect(html).toContain('Не удалось подтвердить email')
    expect(html).not.toContain('backend-only')
  })
})
