import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import type { Grouping } from '../lib/salesPlan'
import {
  catalogSkusQueryKey,
  salesPlanningSectionKey,
  salesPlanQueryKey,
  salesPlansQueryKey,
} from './salesPlanningQueryKeys'

type SalesPlanListResponse = components['schemas']['SalesPlanListResponse']
type SalesPlanDetailResponse = components['schemas']['SalesPlanDetailResponse']
type SalesPlanCreatedResponse =
  components['schemas']['SalesPlanCreatedResponse']
type SalesPlanVersionResponse =
  components['schemas']['SalesPlanVersionResponse']
type CatalogSkuListResponse = components['schemas']['CatalogSkuListResponse']

function query(params: Record<string, string | null>): string {
  const search = new URLSearchParams()
  for (const [name, value] of Object.entries(params)) {
    if (value !== null && value !== '') {
      search.set(name, value)
    }
  }
  const text = search.toString()

  return text === '' ? '' : `?${text}`
}

export function useSalesPlans(companyId: string, cursor: string | null) {
  return useQuery({
    queryKey: salesPlansQueryKey(companyId, cursor),
    enabled: companyId !== '',
    queryFn: () =>
      createCompanyApiClient(companyId).get<SalesPlanListResponse>(
        `/sales-plans${query({ cursor })}`,
      ),
  })
}

export function useSalesPlan(
  companyId: string,
  planId: string,
  groupBy: Grouping,
  cursor: string | null,
) {
  return useQuery({
    queryKey: salesPlanQueryKey(companyId, planId, groupBy, cursor),
    enabled: companyId !== '' && planId !== '',
    queryFn: () =>
      createCompanyApiClient(companyId).get<SalesPlanDetailResponse>(
        `/sales-plans/${encodeURIComponent(planId)}${query({ groupBy, cursor })}`,
      ),
  })
}

export function useCatalogSkus(
  companyId: string,
  marketplaceAccountId: string,
  search: string,
) {
  return useQuery({
    queryKey: catalogSkusQueryKey(companyId, marketplaceAccountId, search),
    enabled: companyId !== '' && marketplaceAccountId !== '',
    queryFn: () =>
      createCompanyApiClient(companyId).get<CatalogSkuListResponse>(
        `/sales-plans/catalog-skus${query({ marketplaceAccountId, search, limit: '20' })}`,
      ),
  })
}

export interface CreateSalesPlanInput {
  marketplaceAccountId: string
  name: string
  dateFrom: string
  dateTo: string
  currency: string
}

export function useCreateSalesPlan(companyId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CreateSalesPlanInput) =>
      createCompanyApiClient(companyId).post<SalesPlanCreatedResponse>(
        '/sales-plans',
        input,
      ),
    onSuccess: () => invalidate(queryClient, companyId),
  })
}

export interface SkuPeriodInput {
  marketplaceSku: string
  totalQuantity: number
  priceMinor: number
}

/**
 * Ввод SKU за период (ADR-040 п. 7). Версия — из последнего прочитанного
 * плана (ADR-008): без неё ввод был бы безусловным и затирал чужую правку.
 */
export function useSetSalesPlanSkus(companyId: string, planId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { version: number; items: SkuPeriodInput[] }) =>
      createCompanyApiClient(companyId).put<SalesPlanVersionResponse>(
        `/sales-plans/${encodeURIComponent(planId)}/skus`,
        input,
      ),
    onSuccess: () => invalidate(queryClient, companyId),
    // После конфликта версия на экране заведомо устарела: повтор упрётся
    // в тот же отказ, пока план не перечитан.
    onError: () => invalidate(queryClient, companyId),
  })
}

export interface ChangeDayInput {
  version: number
  marketplaceSku: string
  date: string
  quantity: number
  /** null — у дня остаётся прежняя цена. */
  priceMinor: number | null
}

/** Правка одного дня SKU в черновике (ADR-040 п. 7). */
export function useChangeSalesPlanDay(companyId: string, planId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: ChangeDayInput) =>
      createCompanyApiClient(companyId).put<SalesPlanVersionResponse>(
        `/sales-plans/${encodeURIComponent(planId)}/days`,
        input,
      ),
    onSuccess: () => invalidate(queryClient, companyId),
    onError: () => invalidate(queryClient, companyId),
  })
}

/** SKU убирается из черновика целиком (ADR-040 п. 8). */
export function useRemoveSalesPlanSku(companyId: string, planId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { version: number; marketplaceSku: string }) =>
      createCompanyApiClient(companyId).delete(
        `/sales-plans/${encodeURIComponent(planId)}/skus/${encodeURIComponent(input.marketplaceSku)}${query({ version: String(input.version) })}`,
      ),
    onSuccess: () => invalidate(queryClient, companyId),
    onError: () => invalidate(queryClient, companyId),
  })
}

function invalidate(
  queryClient: ReturnType<typeof useQueryClient>,
  companyId: string,
): void {
  // Весь раздел, а не одна страница: ввод SKU меняет и итоги в списке
  // планов, и все группировки этого плана.
  void queryClient.invalidateQueries({
    queryKey: salesPlanningSectionKey(companyId),
  })
}
