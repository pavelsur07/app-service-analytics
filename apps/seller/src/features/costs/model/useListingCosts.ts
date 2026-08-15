import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

type ListingCostListResponse = components['schemas']['ListingCostListResponse']

export function listingCostsQueryKey(
  companyId: string,
  cursor: string | null,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'listing-costs', { cursor })
}

export function useListingCosts(
  companyId: string,
  cursor: string | null,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: listingCostsQueryKey(companyId, cursor),
    queryFn: () => {
      const page =
        cursor === null ? '' : `?cursor=${encodeURIComponent(cursor)}`

      return createCompanyApiClient(companyId).get<ListingCostListResponse>(
        `/listing-costs${page}`,
      )
    },
    enabled: options?.enabled ?? true,
  })
}

export interface SetListingCostInput {
  marketplaceAccountId: string
  marketplaceSku: string
  effectiveFrom: string
  unitCostMinor: number
  currency: string
}

/**
 * Новая цена с даты. Прошлое не трогает — этим отличается от
 * исправления, и потому это отдельная мутация, а не флаг в одной
 * (ADR-013).
 */
export function useSetListingCost(companyId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: SetListingCostInput) =>
      createCompanyApiClient(companyId).post<null>('/listing-costs', input),
    onSuccess: () => invalidate(queryClient, companyId),
    // Конфликт «цена с этой даты уже задана» означает, что список
    // на экране устарел: где-то уже есть позиция, которой мы не видим.
    onError: () => invalidate(queryClient, companyId),
  })
}

export interface CorrectListingCostInput {
  costId: string
  unitCostMinor: number
  currency: string
  // Версия из списка обязательна (ADR-008): без неё исправление было бы
  // безусловным и затирало чужую правку.
  version: number
}

/**
 * Исправление уже записанного. Меняет уже показанную прибыль — в этом
 * его смысл, и потому экран предупреждает об этом до отправки.
 */
export function useCorrectListingCost(companyId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: CorrectListingCostInput) =>
      createCompanyApiClient(companyId).put<null>(
        `/listing-costs/${encodeURIComponent(input.costId)}`,
        {
          unitCostMinor: input.unitCostMinor,
          currency: input.currency,
          version: input.version,
        },
      ),
    onSuccess: () => invalidate(queryClient, companyId),
    // Версия в форме после конфликта заведомо устарела, и повторная
    // отправка упрётся в тот же отказ, пока список не перечитан.
    onError: () => invalidate(queryClient, companyId),
  })
}

function invalidate(
  queryClient: ReturnType<typeof useQueryClient>,
  companyId: string,
): void {
  // Инвалидируется весь раздел, а не текущая страница: цена меняет
  // и покрытие («задано у 8 из 62»), и порядок ничего, но соседние
  // страницы в кэше остаются со старой ценой.
  void queryClient.invalidateQueries({
    queryKey: companyQueryKey(companyId, 'ingestion', 'listing-costs'),
  })
}
