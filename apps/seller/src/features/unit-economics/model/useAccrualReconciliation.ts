import { useQuery } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

type AccrualReconciliationResponse =
  components['schemas']['AccrualReconciliationResponse']

export function accrualReconciliationQueryKey(
  companyId: string,
  month: string,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'accrual-reconciliation', {
    month,
  })
}

export function accrualReconciliationPath(month: string): string {
  return `/unit-economics/reconciliation?${new URLSearchParams({ month }).toString()}`
}

/** Начисления Ozon за календарный месяц по группам кабинета (ADR-036). */
export function useAccrualReconciliation(companyId: string, month: string) {
  return useQuery({
    queryKey: accrualReconciliationQueryKey(companyId, month),
    queryFn: () =>
      createCompanyApiClient(companyId).get<AccrualReconciliationResponse>(
        accrualReconciliationPath(month),
      ),
  })
}
