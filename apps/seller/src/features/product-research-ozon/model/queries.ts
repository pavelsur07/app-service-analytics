import { useQuery } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import { categoryCanSearch, pollInterval } from '../lib/research'
import type {
  Availability,
  Categories,
  Project,
  ProjectList,
  ProjectProgress,
  Preview,
} from './types'

const BASE = '/product-research-ozon'
export function researchKey(
  companyId: string,
  entity: string,
  params?: Record<string, unknown>,
) {
  return companyQueryKey(companyId, 'product-research-ozon', entity, params)
}
export function projectPath(projectId: string): string {
  return `${BASE}/projects/${encodeURIComponent(projectId)}`
}
export function uiProjectPath(companyId: string, projectId?: string): string {
  const root = `/companies/${encodeURIComponent(companyId)}/product-research`
  return projectId === undefined
    ? root
    : `${root}/${encodeURIComponent(projectId)}`
}
export function useAvailability(companyId: string) {
  return useQuery({
    queryKey: researchKey(companyId, 'availability'),
    queryFn: () =>
      createCompanyApiClient(companyId).get<Availability>(
        `${BASE}/availability`,
      ),
    staleTime: 60_000,
    retry: false,
  })
}
export function useProjects(companyId: string, cursor: string | null) {
  const query = new URLSearchParams({ limit: '20' })
  if (cursor !== null) query.set('cursor', cursor)
  return useQuery({
    queryKey: researchKey(companyId, 'projects', { cursor }),
    queryFn: () =>
      createCompanyApiClient(companyId).get<ProjectList>(
        `${BASE}/projects?${query}`,
      ),
    retry: false,
  })
}
export function useProject(companyId: string, projectId: string) {
  return useQuery({
    queryKey: researchKey(companyId, 'project', { projectId }),
    queryFn: () =>
      createCompanyApiClient(companyId).get<Project>(projectPath(projectId)),
    retry: false,
  })
}
export function useProgress(
  companyId: string,
  projectId: string,
  deadline: number,
) {
  return useQuery({
    queryKey: researchKey(companyId, 'progress', { projectId }),
    queryFn: () =>
      createCompanyApiClient(companyId).get<ProjectProgress>(
        `${projectPath(projectId)}/progress`,
      ),
    retry: false,
    refetchInterval: (query) =>
      pollInterval(
        query.state.data?.status,
        query.state.error !== null,
        Date.now(),
        deadline,
      ),
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}
export function useCategories(companyId: string, text: string) {
  return useQuery({
    queryKey: researchKey(companyId, 'categories', { text }),
    queryFn: () =>
      createCompanyApiClient(companyId).get<Categories>(
        `${BASE}/categories?${new URLSearchParams({ q: text, limit: '20' })}`,
      ),
    enabled: categoryCanSearch(text),
    staleTime: 30_000,
    retry: false,
  })
}
export function usePreview(
  companyId: string,
  body: Record<string, unknown>,
  valid: boolean,
) {
  return useQuery({
    queryKey: researchKey(companyId, 'preview', body),
    queryFn: () =>
      createCompanyApiClient(companyId).post<Preview>(`${BASE}/preview`, body),
    enabled: valid,
    staleTime: 0,
    retry: false,
  })
}
