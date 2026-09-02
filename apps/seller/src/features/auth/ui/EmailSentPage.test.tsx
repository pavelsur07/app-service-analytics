import { renderToStaticMarkup } from 'react-dom/server'
import { MemoryRouter } from 'react-router'
import { describe, expect, it } from 'vitest'

import { EmailSentPage } from './EmailSentPage'

describe('EmailSentPage', () => {
  it('shows the same neutral result without reflecting an email address', () => {
    const markup = renderToStaticMarkup(
      <MemoryRouter
        initialEntries={['/sign-up/email-sent?email=known@example.com']}
      >
        <EmailSentPage />
      </MemoryRouter>,
    )

    expect(markup).toContain(
      'Если указанный адрес можно использовать, мы отправим на него письмо с инструкциями.',
    )
    expect(markup).not.toContain('known@example.com')
    expect(markup).toContain('href="/resend-confirmation"')
    expect(markup).toContain('href="/login"')
  })
})
