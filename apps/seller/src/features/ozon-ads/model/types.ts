import type { components, paths } from '../../../api/schema'

export type Policy = components['schemas']['ObservationPolicyResponse']
export type AdsEvent = components['schemas']['ObservationEventResponse']
export type PolicyList =
  paths['/api/companies/{companyId}/ozon-ads/policies']['get']['responses'][200]['content']['application/json']
export type EventList =
  paths['/api/companies/{companyId}/ozon-ads/events']['get']['responses'][200]['content']['application/json']
export type CreatePolicy =
  paths['/api/companies/{companyId}/ozon-ads/policies']['post']['requestBody']['content']['application/json']
export type UpdatePolicy =
  paths['/api/companies/{companyId}/ozon-ads/policies/{policyId}']['put']['requestBody']['content']['application/json']
