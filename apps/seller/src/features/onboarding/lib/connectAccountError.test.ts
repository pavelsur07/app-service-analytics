import { describe, expect, it } from 'vitest'

import { connectAccountFailure } from './connectAccountError'

// Обязательное покрытие §10 — разбор ошибок API. У каждого исхода своё
// следующее действие человека, и перепутать их значит отправить его
// выпускать новый ключ там, где надо было подождать.
describe('connectAccountFailure', () => {
  it('говорит проверить ключи, когда площадка их не приняла', () => {
    expect(connectAccountFailure('credentials_rejected').title).toBe(
      'Площадка не приняла ключ',
    )
  })

  it('говорит подождать, а не выпускать ключ, когда площадка не ответила', () => {
    const failure = connectAccountFailure('marketplace_unavailable')

    expect(failure.title).toBe('Ozon сейчас не отвечает')
    expect(failure.description).toContain('Ключ выпускать не нужно')
  })

  it('объясняет занятый кабинет', () => {
    expect(connectAccountFailure('cabinet_already_connected').title).toBe(
      'Кабинет уже подключён',
    )
  })

  it('не обещает лишнего на незнакомом коде', () => {
    // Ответ без тела: упала сеть, прокси отдал HTML. Обещать, что ничего
    // не сохранилось, здесь нельзя — неизвестно, дошёл ли запрос.
    expect(connectAccountFailure(null).title).toBe(
      'Не удалось подключить кабинет',
    )
  })
})
