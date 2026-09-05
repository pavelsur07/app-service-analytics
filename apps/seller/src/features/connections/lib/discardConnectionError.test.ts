import { describe, expect, it } from 'vitest'
import { discardConnectionFailure } from './discardConnectionError'

describe('discardConnectionFailure', () => {
  it('на «уже удалено» требует перечитать список', () => {
    // Строки уже нет в базе — экран обязан обновить список, а не
    // оставлять удалённую карточку на месте.
    const failure = discardConnectionFailure('connection_not_found')

    expect(failure.refetch).toBe(true)
    expect(failure.title).toBe('Подключение уже удалено')
  })

  it('на «есть история» не предлагает перечитывать список', () => {
    // Ничего не изменилось: строка на месте, состояние то же самое.
    const failure = discardConnectionFailure('connection_has_history')

    expect(failure.refetch).toBe(false)
  })

  it('объясняет отказ через следствие и предлагает замену ключа', () => {
    const failure = discardConnectionFailure('connection_has_history')

    expect(failure.description).toContain('замените его')
  })

  it('незнакомый код требует перечитать список', () => {
    // Неизвестно, дошло ли удаление, — безопаснее обновить экран.
    const failure = discardConnectionFailure('some_future_code')

    expect(failure.title).toBe('Не удалось удалить подключение')
    expect(failure.refetch).toBe(true)
  })

  it('ответ без тела разбирается так же, как незнакомый код', () => {
    expect(discardConnectionFailure(null).refetch).toBe(true)
  })
})
