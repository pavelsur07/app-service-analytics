import { companyQueryKey } from './companyQueryKey'

/**
 * Ключ кэша списка подключений. Общий для двух фич — экрана
 * подключений (features/connections) и онбординга (features/onboarding,
 * который после успешного подключения обязан инвалидировать тот же
 * список, иначе клиента вернёт на форму сразу после успеха). ESLint
 * запрещает импорт одной фичи из другой (import/no-restricted-paths,
 * eslint.config.js) — общее уходит в shared, а не в одну из фич.
 */
export function connectionsQueryKey(companyId: string): readonly unknown[] {
  return companyQueryKey(companyId, 'identity', 'connections')
}
