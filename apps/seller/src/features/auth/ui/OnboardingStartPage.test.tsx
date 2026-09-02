import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'

import { OnboardingStartPage } from './OnboardingStartPage'

describe('OnboardingStartPage', () => {
  it('announces the Stage 4 inputs without collecting credentials or rendering company navigation', () => {
    const markup = renderToStaticMarkup(<OnboardingStartPage />)

    expect(markup).toContain('Stage 4')
    expect(markup).toContain('Название магазина')
    expect(markup).toContain('Ozon Client-Id')
    expect(markup).toContain('Ozon Api-Key')
    expect(markup).not.toContain('<form')
    expect(markup).not.toContain('<input')
    expect(markup).not.toContain('<button')
    expect(markup).not.toContain('<nav')
    expect(markup).not.toContain('href="/companies')
  })
})
