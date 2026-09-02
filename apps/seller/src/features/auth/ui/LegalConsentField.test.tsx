import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'

import { LegalConsentField } from './LegalConsentField'

describe('LegalConsentField', () => {
  it('requires an unchecked, accessible consent to all legal documents', () => {
    const markup = renderToStaticMarkup(
      <LegalConsentField
        checked={false}
        name="legalConsent"
        onBlur={() => undefined}
        onChange={() => undefined}
      />,
    )

    expect(markup).toContain('type="checkbox"')
    expect(markup).not.toContain('checked=""')
    expect(markup).toContain('name="legalConsent"')
    expect(markup).toContain('Я принимаю')

    for (const href of [
      'https://conwix.com/privacy.html',
      'https://conwix.com/oferta.html',
      'https://conwix.com/personal-data.html',
    ]) {
      expect(markup).toContain(
        `href="${href}" target="_blank" rel="noreferrer"`,
      )
    }

    expect(markup).toMatch(/<label[^>]*>.*<input[^>]*>.*Я принимаю.*<\/label>/s)
  })
})
