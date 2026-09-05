import { describe, expect, it } from 'vitest'

import {
  ADD_ANOTHER_CABINET_INTENT,
  onboardingPathToAddAnotherCabinet,
} from './onboardingIntent'

// Обязательное покрытие §10 — единственная функция, которая собирает
// адрес формы подключения с явным намерением; ConnectionsPage и
// resolveOnboardingDecision (features/onboarding) должны видеть один
// и тот же признак, а не два похожих литерала, случайно разошедшихся.
describe('onboardingPathToAddAnotherCabinet', () => {
  it('несёт companyId и признак осознанного добавления в адресе', () => {
    expect(onboardingPathToAddAnotherCabinet('company-a')).toBe(
      `/onboarding?company=company-a&intent=${ADD_ANOTHER_CABINET_INTENT}`,
    )
  })

  it('кодирует companyId, не разрушая признак рядом с ним', () => {
    // Признак не должен ломать экранирование companyId — и наоборот:
    // символ, который экранируется в companyId, не должен затронуть
    // параметр intent, идущий следом в той же строке.
    const rawCompanyId = 'company a/b'

    expect(onboardingPathToAddAnotherCabinet(rawCompanyId)).toBe(
      `/onboarding?company=company%20a%2Fb&intent=${ADD_ANOTHER_CABINET_INTENT}`,
    )
  })
})
