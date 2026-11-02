import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { Badge, Button, Card } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import { projectStatus } from '../lib/research'
import { uiProjectPath } from '../model/queries'
import { useResearchProject } from '../model/useResearchProject'
import { ResearchError } from './ResearchError'
import { ResearchForm } from './ResearchForm'
import { ResearchGate } from './ResearchGate'
import { ProjectMonths } from './ProjectMonths'

export function ResearchProjectPage() {
  const { companyId, projectId } = useParams<{
    companyId: string
    projectId: string
  }>()
  if (companyId === undefined || projectId === undefined) return null
  return (
    <ResearchGate key={`${companyId}:${projectId}`} companyId={companyId}>
      <Project companyId={companyId} projectId={projectId} />
    </ResearchGate>
  )
}
function Project({
  companyId,
  projectId,
}: {
  companyId: string
  projectId: string
}) {
  const {
    project,
    progress,
    start,
    paused,
    unconfirmed,
    resume,
    versionSynced,
    loading,
    partial,
    available,
    doStart,
  } = useResearchProject(companyId, projectId)
  const [form, setForm] = useState<'copy' | 'add' | null>(null)
  const denied = [project.error, progress.error].find(
    (error) =>
      error instanceof ApiError &&
      (error.status === 401 || error.status === 403),
  )
  if (denied !== undefined) return <ResearchError error={denied} />
  if (project.isPending) return <p role="status">Открываем проект…</p>
  if (project.isError)
    return (
      <ResearchError
        error={project.error}
        retry={() => {
          void project.refetch()
        }}
      />
    )
  const data = project.data
  const state = progress.data
  const currentProject = data
  return (
    <>
      <div className="flex flex-col gap-3">
        <Link className="text-sm" to={uiProjectPath(companyId)}>
          ← Все исследования
        </Link>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h1 className="text-xl font-semibold break-words">{data.name}</h1>
            <p className="mt-1 text-sm break-words text-text-secondary">
              {data.categoryPath}
            </p>
          </div>
          {state !== undefined && (
            <Badge
              tone={
                state.status === 'loaded'
                  ? 'positive'
                  : state.status === 'error'
                    ? 'negative'
                    : 'neutral'
              }
            >
              {projectStatus(state.status)}
            </Badge>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          <Badge>FBO + FBS включены</Badge>
          <Badge>RUB</Badge>
        </div>
        {data.comment !== '' && (
          <p className="whitespace-pre-wrap text-sm text-text-secondary">
            {data.comment}
          </p>
        )}
      </div>
      <Card>
        <div className="flex flex-col gap-3">
          <p className="text-sm text-text-secondary">
            {data.preview?.summary} · Категория и FBS закреплены. Для другой
            категории создайте копию.
          </p>
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              variant="primary"
              disabled={
                (!available && !unconfirmed) ||
                start.isPending ||
                (progress.isError && !unconfirmed)
              }
              onClick={doStart}
            >
              {start.isPending
                ? 'Отправляем задание…'
                : unconfirmed
                  ? 'Подтвердить запуск'
                  : state?.status === 'loaded' && partial
                    ? 'Обновить текущий месяц'
                    : state?.status === 'partially_loaded' ||
                        state?.status === 'error'
                      ? 'Дозагрузить неудачные и новые месяцы'
                      : 'Загрузить'}
            </Button>
            <Button
              type="button"
              variant="secondary"
              disabled={start.isPending || !versionSynced}
              onClick={() => {
                setForm('add')
              }}
            >
              Добавить периоды
            </Button>
            <Button
              type="button"
              variant="secondary"
              disabled={start.isPending || !versionSynced}
              onClick={() => {
                setForm('copy')
              }}
            >
              Скопировать проект
            </Button>
            <Button
              type="button"
              variant="ghost"
              disabled={progress.isFetching}
              onClick={resume}
            >
              Проверить прогресс
            </Button>
          </div>
          {loading && (
            <p role="status" className="text-sm text-text-secondary">
              Загрузка продолжается. Можно закрыть страницу и вернуться к
              проекту позже.
            </p>
          )}
          {state?.status === 'loaded' && !partial && (
            <p className="text-sm text-positive-text">
              Все выбранные месяцы загружены.
            </p>
          )}
        </div>
      </Card>
      {form !== null && (
        <ResearchForm
          key={form}
          companyId={companyId}
          project={currentProject}
          mode={form}
          blocked={!versionSynced}
          onCancel={() => {
            setForm(null)
          }}
        />
      )}
      {start.isError && <ResearchError error={start.error} />}
      {progress.isPending && <p role="status">Проверяем месяцы проекта…</p>}
      {progress.isError && (
        <>
          <ResearchError error={progress.error} retry={resume} />
          {state !== undefined && (
            <p role="alert" className="text-sm text-warning-text">
              Показан последний полученный прогресс. Обновление не подтверждено.
            </p>
          )}
        </>
      )}
      {paused && loading && (
        <Card tone="warning">
          <p role="alert" className="text-sm text-warning-text">
            Автоматическая проверка приостановлена после 10 минут. Данные могли
            измениться.
          </p>
          <Button
            type="button"
            variant="secondary"
            size="compact"
            onClick={resume}
          >
            Продолжить проверку
          </Button>
        </Card>
      )}
      {state !== undefined && (
        <ProjectMonths state={state} checkedAt={progress.dataUpdatedAt} />
      )}
    </>
  )
}
