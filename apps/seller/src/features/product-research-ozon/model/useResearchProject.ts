import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import {
  loadRequired,
  POLL_WINDOW,
  startInput,
  uncertainWrite,
} from '../lib/research'
import { projectPath, researchKey, useProgress, useProject } from './queries'
import type { Run, StartInput } from './types'

export function useResearchProject(companyId: string, projectId: string) {
  const project = useProject(companyId, projectId)
  const [deadline, setDeadline] = useState(() => Date.now() + POLL_WINDOW)
  const [paused, setPaused] = useState(false)
  const progress = useProgress(companyId, projectId, paused ? 0 : deadline)
  const [unconfirmed, setUnconfirmed] = useState(false)
  const intent = useRef<StartInput | null>(null)
  const locked = useRef(false)
  const alive = useRef(true)
  const queryClient = useQueryClient()
  useEffect(() => {
    alive.current = true
    return () => {
      alive.current = false
    }
  }, [])
  useEffect(() => {
    const timer = setTimeout(
      () => {
        setPaused(true)
      },
      Math.max(0, deadline - Date.now()),
    )
    return () => {
      clearTimeout(timer)
    }
  }, [deadline])
  const resume = () => {
    setPaused(false)
    setDeadline(Date.now() + POLL_WINDOW)
    void Promise.all([progress.refetch(), project.refetch()])
  }
  const start = useMutation({
    mutationFn: (input: StartInput) =>
      createCompanyApiClient(companyId).post<Run>(
        `${projectPath(projectId)}/runs`,
        input,
      ),
    onMutate: async () => {
      await queryClient.cancelQueries({
        queryKey: researchKey(companyId, 'progress', { projectId }),
      })
    },
    onSuccess: async () => {
      intent.current = null
      if (alive.current) setUnconfirmed(false)
      await queryClient.invalidateQueries({
        queryKey: researchKey(companyId, 'project', { projectId }),
      })
      await queryClient.invalidateQueries({
        queryKey: researchKey(companyId, 'progress', { projectId }),
      })
      await queryClient.invalidateQueries({
        queryKey: researchKey(companyId, 'projects'),
      })
      if (alive.current) {
        setPaused(false)
        setDeadline(Date.now() + POLL_WINDOW)
      }
    },
    onError: async (error) => {
      if (uncertainWrite(error)) {
        if (alive.current) setUnconfirmed(true)
      } else {
        if (alive.current) setUnconfirmed(false)
        intent.current = null
        await queryClient.invalidateQueries({
          queryKey: researchKey(companyId, 'project', { projectId }),
        })
        await queryClient.invalidateQueries({
          queryKey: researchKey(companyId, 'progress', { projectId }),
        })
      }
    },
  })
  useEffect(() => {
    const metaVersion = project.data?.version
    const progressVersion = progress.data?.version
    if (
      metaVersion === undefined ||
      progressVersion === undefined ||
      metaVersion === progressVersion
    )
      return
    if (
      metaVersion < progressVersion &&
      !project.isFetching &&
      !project.isError
    ) {
      void queryClient.invalidateQueries({
        queryKey: researchKey(companyId, 'project', { projectId }),
      })
    }
    if (
      metaVersion > progressVersion &&
      !progress.isFetching &&
      !progress.isError
    ) {
      void queryClient.invalidateQueries({
        queryKey: researchKey(companyId, 'progress', { projectId }),
      })
    }
  }, [
    project.data?.version,
    progress.data?.version,
    project.isFetching,
    project.isError,
    progress.isFetching,
    progress.isError,
    queryClient,
    companyId,
    projectId,
  ])
  const state = progress.data
  const versionSynced =
    state !== undefined && state.version === project.data?.version
  const loading = state?.status === 'loading'
  const partial = project.data?.preview?.months.some((m) => m.partial) ?? false
  const needsReload = loadRequired(state, project.data?.preview)
  const available =
    state !== undefined &&
    versionSynced &&
    !loading &&
    (state.status !== 'loaded' || needsReload)
  const doStart = () => {
    const metadata = project.data
    if (
      (!available && !unconfirmed) ||
      locked.current ||
      (progress.isError && !unconfirmed) ||
      metadata === undefined ||
      metadata.preview === null ||
      metadata.preview === undefined
    )
      return
    const body = startInput(
      intent.current,
      metadata.version,
      metadata.preview.calendarDate,
    )
    intent.current = body
    locked.current = true
    void start
      .mutateAsync(body)
      .catch(() => undefined)
      .finally(() => {
        locked.current = false
      })
  }
  return {
    project,
    progress,
    start,
    paused,
    unconfirmed,
    resume,
    versionSynced,
    loading,
    partial,
    needsReload,
    available,
    doStart,
  }
}
