import { describe, expect, it } from 'vitest'
import { statusPresentation } from './statusPresentation'

describe('statusPresentation', () => {
  it('marks delivered as positive', () => {
    expect(statusPresentation('delivered')).toEqual({
      tone: 'positive',
      label: 'Доставлено',
    })
  })

  it('marks cancelled as negative', () => {
    expect(statusPresentation('cancelled')).toEqual({
      tone: 'negative',
      label: 'Отменено',
    })
  })

  it('falls back to a neutral tone with the raw status for unknown values', () => {
    // Ozon может завести статус, которого здесь нет (ADR-009) — не должно падать.
    expect(statusPresentation('some_future_status')).toEqual({
      tone: 'neutral',
      label: 'some_future_status',
    })
  })
})
