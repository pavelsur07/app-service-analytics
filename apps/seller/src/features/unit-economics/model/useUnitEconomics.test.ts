import { describe, expect, it } from 'vitest'
import { unitEconomicsQueryKey } from './useUnitEconomics'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

describe('ключ кэша юнит-экономики', () => {
  it('содержит companyId и различает компании', () => {
    // Проверяется ключ самого хука (CLAUDE.md §7): потерянный companyId
    // означал бы, что после переключения компании экран покажет чужую
    // выручку и чужие расходы — на экране, ради которого сюда и заходят.
    expect(unitEconomicsQueryKey(ONE, 30)).toContain(ONE)
    expect(unitEconomicsQueryKey(ONE, 30)).not.toEqual(
      unitEconomicsQueryKey(TWO, 30),
    )
  })

  it('различает окна одной компании', () => {
    // Иначе переключение «7 дней / 30 дней» отдало бы прежние числа
    // из кэша, и клиент решил бы, что за неделю заработал столько же,
    // сколько за месяц.
    expect(unitEconomicsQueryKey(ONE, 7)).not.toEqual(
      unitEconomicsQueryKey(ONE, 30),
    )
  })
})
