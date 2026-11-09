import { getResponse, HttpResponse, http as rawHttp } from 'msw'
import { createOpenApiHttp } from 'openapi-msw'

import type { components, paths } from '../../../api/schema'
import type { CreatePolicy, Policy, UpdatePolicy } from '../model/types'

export const ADS_ACCOUNT = '019a0000-0000-7000-8000-000000000098'
const policyId = '019a0000-0000-7000-8000-000000000099'
export const ADS_STAMP = '2026-11-08T10:00:00Z'

// Included in the seller strict tsconfig. The browser adapter only forwards
// requests; every mock response is resolved by MSW/OpenAPI handlers here.
export function createAdsMock(company: string, second: string) {
  const http = createOpenApiHttp<paths>({
    baseUrl: 'http://app.conwix.internal',
  })
  const lists = new Map<string, Policy[]>([
    [company, []],
    [second, []],
  ])
  let writes = 0
  let unknown = false
  let conflict = false
  let listError = false
  let enabled = true
  let gateError = false
  let hold = false
  let release: (() => void) | null = null
  let catalogHold: string | null = null
  let releaseCatalog: (() => void) | null = null
  const reads: string[] = []
  // A proxy 503 is intentionally outside these endpoints' OpenAPI contract.
  // Raw MSW exercises parsing of an unconfirmed infrastructure response.
  const unavailable = () =>
    HttpResponse.json<components['schemas']['ValidationErrorResponse']>(
      {
        status: 503,
        code: 'unavailable',
        message: 'private',
      } satisfies components['schemas']['ValidationErrorResponse'],
      { status: 503 },
    )
  async function save(
    owner: string,
    input: CreatePolicy | UpdatePolicy,
  ): Promise<Policy | 'network' | 'conflict'> {
    writes++
    if (hold)
      await new Promise<void>((resolve) => {
        release = resolve
      })
    if (unknown) return 'network'
    if (conflict) return 'conflict'
    const policy: Policy = {
      id: policyId,
      accountId: ADS_ACCOUNT,
      campaignId: 'campaignId' in input ? input.campaignId : '49761882',
      version: 'version' in input ? input.version + 1 : 1,
      mode: 'observe',
      enabled: input.enabled,
      dailyLimit: input.dailyLimit,
      start: input.start,
      stop: input.stop,
      timezone: 'Europe/Moscow',
      pollSeconds: input.pollSeconds,
      deliveryStatus: 'saved',
      observation: null,
      sourceAgeKnown: false,
      budgetProtectionActive: false,
    }
    lists.set(owner, [policy])
    return policy
  }
  const handlers = [
    // These infrastructure errors are intentionally outside the OpenAPI success/error statuses.
    rawHttp.get(
      'http://app.conwix.internal/api/companies/:companyId/ozon-ads/availability',
      () => (gateError ? unavailable() : undefined),
    ),
    rawHttp.get(
      'http://app.conwix.internal/api/companies/:companyId/ozon-ads/policies',
      () => (listError ? unavailable() : undefined),
    ),
    rawHttp.get(
      'http://app.conwix.internal/api/companies/:companyId/ozon-advertising/campaigns',
      () => (listError ? unavailable() : undefined),
    ),
    http.get(
      '/api/companies/{companyId}/ozon-advertising/campaigns',
      async ({ params, response }) => {
        if (params.companyId === catalogHold)
          await new Promise<void>((resolve) => {
            releaseCatalog = resolve
          })
        return response(200).json({
          status: 'ready',
          receivedAt: ADS_STAMP,
          savedCount: 2,
          total: 2,
          nextCursor: null,
          items: [
            {
              id: '49761882',
              title:
                params.companyId === company
                  ? 'Тестовая CPC-кампания'
                  : 'Кампания другой компании',
              state: 'CAMPAIGN_STATE_RUNNING',
              advObjectType: 'SKU',
              paymentType: 'CPC',
            },
            {
              id: '49761883',
              title: 'Кампания с оплатой за показы',
              state: 'CAMPAIGN_STATE_RUNNING',
              advObjectType: 'SKU',
              paymentType: 'CPM',
            },
          ],
        })
      },
    ),
    http.get('/api/companies/{companyId}/connections', ({ response }) =>
      response(200).json({
        connections: [
          {
            id: ADS_ACCOUNT,
            marketplace: 'ozon',
            externalShopId: 'Sandbox Ads',
            state: 'active',
            advertisingState: 'active',
            createdAt: ADS_STAMP,
            lastLoadedAt: {},
            version: 1,
          },
        ],
      }),
    ),
    http.get(
      '/api/companies/{companyId}/ozon-ads/availability',
      ({ response }) => response(200).json({ enabled }),
    ),
    http.get(
      '/api/companies/{companyId}/ozon-ads/policies',
      ({ params, response }) =>
        response(200).json({
          items: lists.get(params.companyId) ?? [],
          nextCursor: null,
        }),
    ),
    http.get(
      '/api/companies/{companyId}/ozon-ads/events',
      ({ params, response }) =>
        response(200).json({
          items: (lists.get(params.companyId) ?? []).map((policy) => ({
            id: policyId,
            policyId,
            accountId: ADS_ACCOUNT,
            campaignId: policy.campaignId,
            version: policy.version,
            kind: 'policy_created',
            actorId: null,
            action: 'saved',
            reason: 'user_change',
            details: {
              start: policy.start,
              stop: policy.stop,
              enabled: policy.enabled,
              pollSeconds: policy.pollSeconds,
            },
            occurredAt: ADS_STAMP,
            dailyLimit: policy.dailyLimit,
            spend: null,
            difference: null,
          })),
          nextCursor: null,
        }),
    ),
    http.post(
      '/api/companies/{companyId}/ozon-ads/policies',
      async ({ params, request, response }) => {
        const result = await save(params.companyId, await request.json())
        if (result === 'network') return HttpResponse.error()
        if (result === 'conflict')
          return response(409).json({
            status: 409,
            code: 'version_conflict',
            message: 'private',
          })
        return response(201).json(result)
      },
    ),
    http.put(
      '/api/companies/{companyId}/ozon-ads/policies/{policyId}',
      async ({ params, request, response }) => {
        const result = await save(params.companyId, await request.json())
        if (result === 'network') return HttpResponse.error()
        if (result === 'conflict')
          return response(409).json({
            status: 409,
            code: 'version_conflict',
            message: 'private',
          })
        return response(200).json(result)
      },
    ),
  ]
  return {
    lists,
    reads,
    writes: () => writes,
    holdCatalog: (owner: string) => {
      catalogHold = owner
    },
    releaseCatalog: () => {
      catalogHold = null
      releaseCatalog?.()
    },
    gateError: (value: boolean) => {
      gateError = value
    },
    holdWrites: () => {
      hold = true
    },
    release: () => {
      hold = false
      release?.()
    },
    setUnknown: () => {
      unknown = true
    },
    setConflict: () => {
      conflict = true
    },
    setListError: () => {
      listError = true
    },
    disable: () => {
      enabled = false
    },
    response: (request: Request) => {
      reads.push(new URL(request.url).pathname)
      return getResponse(handlers, request)
    },
  }
}
