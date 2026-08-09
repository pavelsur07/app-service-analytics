// Единственное место, собирающее ключ кэша TanStack Query для данных
// компании (CLAUDE.md §7). Форма: ['company', companyId, модуль, сущность,
// ...параметры] — companyId вторым элементом всегда, иначе при переключении
// компании клиент отдаст данные предыдущей из кэша.
export function companyQueryKey(
  companyId: string,
  module: string,
  entity: string,
  params?: Record<string, unknown>,
): readonly unknown[] {
  return params === undefined
    ? (['company', companyId, module, entity] as const)
    : (['company', companyId, module, entity, params] as const)
}
