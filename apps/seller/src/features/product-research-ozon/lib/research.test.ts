import { describe, expect, it } from 'vitest'

import {
  categoryCanSearch,
  requestId,
  pollInterval,
  researchFailure,
  monthStatus,
} from './research'

describe('границы пользовательского пути исследования', () => {
  it('поиск требует три буквы, а не длину строки', () => {
    expect(categoryCanSearch('12х')).toBe(false)
    expect(categoryCanSearch('хи')).toBe(false)
    expect(categoryCanSearch(' хид ')).toBe(true)
  })

  it('requestId версии7 с RFC variant и полной временной частью', () => {
    const id = requestId(1_700_000_000_000, new Uint8Array(16))
    expect(id).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    )
    expect(id.replaceAll('-', '').slice(0, 12)).toBe(
      (1_700_000_000_000).toString(16).padStart(12, '0'),
    )
  })

  it('poll ограничен временем и прекращается при terminal/error', () => {
    expect(pollInterval('loading', false, 100, 500)).toBe(5000)
    expect(pollInterval('loaded', false, 100, 500)).toBe(false)
    expect(pollInterval('loading', true, 100, 500)).toBe(false)
    expect(pollInterval('loading', false, 501, 500)).toBe(false)
    expect(pollInterval('partially_loaded', false, 100, 500)).toBe(false)
  })

  it('ошибка токена и пустая категория не превращаются в успешную цифру', () => {
    expect(researchFailure('credentials_rejected')).toContain('токен MPStats')
    expect(monthStatus('ready')).toBe('Готово')
    expect(monthStatus('missing')).toBe('Не загружен')
  })
})
