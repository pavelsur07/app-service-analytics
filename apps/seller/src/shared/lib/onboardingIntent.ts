/**
 * Признак осознанного намерения добавить ещё один кабинет — клиент сам
 * нажал «Подключить кабинет» на экране подключений (features/connections),
 * у которого уже есть хотя бы одно подключение, а не оказался на форме
 * автоматическим редиректом гейта CompanyLayout (`resolveCompanyGate`)
 * из-за отсутствия активного подключения.
 *
 * Без признака `resolveOnboardingDecision` (features/onboarding) уводит
 * компанию с подключениями обратно на экран подключений — это и есть
 * защита от тупика «сломанное подключение → форма → 409
 * cabinet_already_connected», которую признак не отменяет, а обходит
 * только там, где клиент попросил об этом явно.
 *
 * Общий для двух фич — connections пишет параметр в адрес, onboarding
 * читает его при решении — и уходит в shared, а не в одну из фич:
 * ESLint запрещает импорт одной фичи из другой
 * (import/no-restricted-paths, eslint.config.js). Тот же приём, что
 * у connectionsQueryKey.ts.
 */
export const ADD_ANOTHER_CABINET_INTENT = 'add-another-cabinet'

/**
 * Адрес формы подключения кабинета с явным намерением добавить второй,
 * а не первый. Единственное место, собирающее эту строку: значение
 * параметра в ссылке (ConnectionsPage) и сравнение при решении
 * (resolveOnboardingDecision) читают его отсюда, а не дублируют
 * литералом в двух файлах.
 *
 * encodeURIComponent — только вокруг companyId: значение intent —
 * фиксированный литерал без символов, которые экранирование меняет,
 * а companyId приходит от сервера и может содержать что угодно (тот же
 * приём, что connectionsPath в CompanyLayout.tsx и OnboardingStartPage.tsx).
 */
export function onboardingPathToAddAnotherCabinet(companyId: string): string {
  return `/onboarding?company=${encodeURIComponent(companyId)}&intent=${ADD_ANOTHER_CABINET_INTENT}`
}
