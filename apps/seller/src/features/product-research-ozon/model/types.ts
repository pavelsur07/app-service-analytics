import type { components, paths } from '../../../api/schema'

export type Project = components['schemas']['ProjectResponse']
export type ProjectProgress = components['schemas']['ProjectProgressResponse']
export type ProjectMonth = components['schemas']['ProjectMonthResponse']
export type ProjectList = components['schemas']['ProjectsResponse']
export type Preview = components['schemas']['PreviewResponse']
export type Categories = components['schemas']['ResearchCategoriesResponse']
export type Availability = components['schemas']['ResearchAvailabilityResponse']
export type Run = components['schemas']['RunResponse']
export type Period = components['schemas']['PeriodResponse']
export type StartInput =
  paths['/api/companies/{companyId}/product-research-ozon/projects/{projectId}/runs']['post']['requestBody']['content']['application/json']
