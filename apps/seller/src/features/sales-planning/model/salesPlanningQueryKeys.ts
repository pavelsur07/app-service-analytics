import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import type { Grouping } from '../lib/salesPlan'

// Все ключи раздела — через общий хелпер (CLAUDE.md §7): companyId
// вторым элементом, иначе при смене компании экран отдал бы план
// предыдущей из кэша. План продаж — коммерческая тайна, и такая
// подмена была бы утечкой, а не устаревшими данными.
const MODULE = 'sales-planning'

export function salesPlanningSectionKey(companyId: string): readonly unknown[] {
  return companyQueryKey(companyId, MODULE, 'all').slice(0, 3)
}

export function salesPlansQueryKey(
  companyId: string,
  cursor: string | null,
): readonly unknown[] {
  return companyQueryKey(companyId, MODULE, 'plans', { cursor })
}

export function salesPlanQueryKey(
  companyId: string,
  planId: string,
  groupBy: Grouping,
  cursor: string | null,
): readonly unknown[] {
  return companyQueryKey(companyId, MODULE, 'plan', { planId, groupBy, cursor })
}

export function catalogSkusQueryKey(
  companyId: string,
  marketplaceAccountId: string,
  search: string,
): readonly unknown[] {
  return companyQueryKey(companyId, MODULE, 'catalog-skus', {
    marketplaceAccountId,
    search,
  })
}
