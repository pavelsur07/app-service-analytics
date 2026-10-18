import { describe, expect, it } from 'vitest'
import {
  catalogSkusQueryKey,
  salesPlanningSectionKey,
  salesPlanQueryKey,
  salesPlansQueryKey,
} from './salesPlanningQueryKeys'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'
const PLAN = '019ffe00-0000-7000-8000-0000000000aa'

describe('ключи кэша плана продаж', () => {
  it('содержат companyId и различают компании', () => {
    // CLAUDE.md §7: план чужой компании из кэша — утечка коммерческой тайны.
    expect(salesPlansQueryKey(ONE, null)).toContain(ONE)
    expect(salesPlansQueryKey(ONE, null)).not.toEqual(
      salesPlansQueryKey(TWO, null),
    )
    expect(salesPlanQueryKey(ONE, PLAN, 'period', null)).not.toEqual(
      salesPlanQueryKey(TWO, PLAN, 'period', null),
    )
    expect(catalogSkusQueryKey(ONE, PLAN, '')).not.toEqual(
      catalogSkusQueryKey(TWO, PLAN, ''),
    )
  })

  it('различают план, группировку и страницу', () => {
    expect(salesPlanQueryKey(ONE, PLAN, 'period', null)).not.toEqual(
      salesPlanQueryKey(ONE, PLAN, 'week', null),
    )
    expect(salesPlanQueryKey(ONE, PLAN, 'day', null)).not.toEqual(
      salesPlanQueryKey(ONE, PLAN, 'day', '101'),
    )
    expect(salesPlansQueryKey(ONE, null)).not.toEqual(
      salesPlansQueryKey(ONE, PLAN),
    )
  })

  it('раздел — префикс каждого ключа своей компании и только её', () => {
    const section = salesPlanningSectionKey(ONE)
    for (const key of [
      salesPlansQueryKey(ONE, null),
      salesPlanQueryKey(ONE, PLAN, 'month', null),
      catalogSkusQueryKey(ONE, PLAN, 'крем'),
    ]) {
      expect(key.slice(0, section.length)).toEqual(section)
    }
    expect(salesPlansQueryKey(TWO, null).slice(0, section.length)).not.toEqual(
      section,
    )
  })
})
