import { describe, expect, it } from 'vitest'
import {
  accrualReconciliationPath,
  accrualReconciliationQueryKey,
} from './useAccrualReconciliation'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

describe('ключ кэша сверки', () => {
  it('содержит companyId и различает компании', () => {
    // CLAUDE.md §7: без companyId после переключения компании вкладка
    // показала бы итог к начислению чужого кабинета.
    expect(accrualReconciliationQueryKey(ONE, '2026-08')).toContain(ONE)
    expect(accrualReconciliationQueryKey(ONE, '2026-08')).not.toEqual(
      accrualReconciliationQueryKey(TWO, '2026-08'),
    )
  })

  it('различает месяцы', () => {
    expect(accrualReconciliationQueryKey(ONE, '2026-07')).not.toEqual(
      accrualReconciliationQueryKey(ONE, '2026-08'),
    )
  })
})

describe('строка запроса сверки', () => {
  it('несёт месяц', () => {
    expect(accrualReconciliationPath('2026-08')).toBe(
      '/unit-economics/reconciliation?month=2026-08',
    )
  })
})
