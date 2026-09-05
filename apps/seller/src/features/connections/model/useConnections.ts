import { connectionsQueryKey } from '../../../shared/lib/connectionsQueryKey'
import { useConnections } from '../../../shared/model/useConnections'

/**
 * Реэкспорт: и ключ кэша, и сам хук определены в shared (нужны не
 * только этой фиче, но и app/CompanyLayout и features/onboarding,
 * а один feature не импортирует другой напрямую —
 * import/no-restricted-paths, eslint.config.js). Здесь остаются
 * публичные имена для уже существующих вызовов внутри этой же фичи
 * (ConnectionsPage.tsx, useReplaceCredentials.ts и тесты).
 */
export { connectionsQueryKey, useConnections }
