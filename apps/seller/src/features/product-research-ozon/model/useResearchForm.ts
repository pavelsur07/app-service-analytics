import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'
import { ApiError } from '../../../api/ApiError'
import { createCompanyApiClient } from '../../../api/companyClient'
import { calendarDate, uncertainWrite } from '../lib/research'
import {
  displayedPeriods,
  previewInput,
  reachesCurrent,
  validPeriods,
  type Selection,
} from '../lib/selection'
import { projectPath, researchKey, uiProjectPath, usePreview } from './queries'
import type { Project } from './types'

export function useResearchForm(
  companyId: string,
  mode: 'create' | 'copy' | 'add',
  onCancel: () => void,
  project: Project | undefined,
  blocked: boolean,
) {
  const [category, setCategory] = useState<string | null>(
    project?.categoryPath ?? null,
  )
  const [selection, setSelection] = useState<Selection>(() =>
    mode === 'create'
      ? { shortcut: { kind: 'last_closed_months', months: 3 } }
      : mode === 'add'
        ? { periods: [{ fromMonth: '', toMonth: '', name: '' }] }
        : { periods: project?.preview?.periods ?? [] },
  )
  const [includeCurrent, setIncludeCurrent] = useState(
    project?.includeCurrentMonth ?? false,
  )
  const [name, setName] = useState('')
  const [comment, setComment] = useState(
    mode === 'copy' ? (project?.comment ?? '') : '',
  )
  const [unknown, setUnknown] = useState(false)
  const locked = useRef(false)
  const alive = useRef(true)
  useEffect(() => {
    alive.current = true
    return () => {
      alive.current = false
    }
  }, [])
  const navigate = useNavigate()
  const client = useQueryClient()
  const today = calendarDate()
  const existing = mode === 'add' ? (project?.preview?.periods ?? []) : []
  const explicit = 'periods' in selection ? selection.periods : []
  const currentVisible = reachesCurrent([...existing, ...explicit], today)
  const flag =
    (mode === 'add' && project?.includeCurrentMonth === true) ||
    (currentVisible && includeCurrent)
  const valid =
    !('periods' in selection) || validPeriods(selection.periods, today)
  const preview = usePreview(
    companyId,
    previewInput(selection, flag, category, existing),
    valid,
  )
  const periods = displayedPeriods(selection, preview.data)
  const canSave =
    category !== null &&
    !blocked &&
    valid &&
    preview.isSuccess &&
    !preview.isFetching &&
    !unknown
  const mutation = useMutation({
    mutationFn: async () => {
      if (!canSave || preview.data === undefined)
        throw new Error('preview_required')
      const api = createCompanyApiClient(companyId)
      if (mode === 'add' && project !== undefined) {
        return api.post<Project>(`${projectPath(project.id)}/periods`, {
          periods,
          includeCurrentMonth: flag,
          expectedVersion: project.version,
          previewCalendarDate: preview.data.calendarDate,
        })
      }
      if (mode === 'copy' && project !== undefined) {
        return api.post<Project>(`${projectPath(project.id)}/copy`, {
          expectedVersion: project.version,
          previewCalendarDate: preview.data.calendarDate,
          overrides: {
            categoryPath: category,
            ...selection,
            includeCurrentMonth: flag,
            ...(name.trim() === '' ? {} : { name }),
            comment,
          },
        })
      }
      return api.post<Project>('/product-research-ozon/projects', {
        categoryPath: category,
        ...selection,
        includeCurrentMonth: flag,
        previewCalendarDate: preview.data.calendarDate,
        ...(name.trim() === '' ? {} : { name }),
        comment,
      })
    },
    onSuccess: async (saved) => {
      await client.invalidateQueries({
        queryKey: researchKey(companyId, 'projects'),
      })
      await client.invalidateQueries({
        queryKey: researchKey(companyId, 'project', { projectId: saved.id }),
      })
      await client.invalidateQueries({
        queryKey: researchKey(companyId, 'progress', { projectId: saved.id }),
      })
      if (!alive.current) return
      if (mode === 'add') onCancel()
      else await navigate(uiProjectPath(companyId, saved.id))
    },
    onError: async (error) => {
      if (error instanceof ApiError && error.code === 'preview_outdated')
        await preview.refetch()
      if (
        project !== undefined &&
        error instanceof ApiError &&
        error.code === 'version_conflict'
      ) {
        await client.invalidateQueries({
          queryKey: researchKey(companyId, 'project', {
            projectId: project.id,
          }),
        })
        await client.invalidateQueries({
          queryKey: researchKey(companyId, 'progress', {
            projectId: project.id,
          }),
        })
      }
      if (alive.current && mode !== 'add' && uncertainWrite(error))
        setUnknown(true)
    },
  })
  const submit = () => {
    if (!canSave || locked.current) return
    locked.current = true
    void mutation
      .mutateAsync()
      .catch(() => undefined)
      .finally(() => {
        locked.current = false
      })
  }
  const checkList = async () => {
    await client.invalidateQueries({
      queryKey: researchKey(companyId, 'projects'),
    })
    if (!alive.current) return
    onCancel()
    await navigate(uiProjectPath(companyId))
  }
  return {
    category,
    setCategory,
    selection,
    setSelection,
    includeCurrent,
    setIncludeCurrent,
    name,
    setName,
    comment,
    setComment,
    unknown,
    today,
    currentVisible,
    flag,
    valid,
    preview,
    canSave,
    mutation,
    checkList,
    submit,
  }
}
