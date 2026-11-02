import { useState } from 'react'
import { FolderSearch } from 'lucide-react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'

import {
  Badge,
  Button,
  Card,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { projectStatus, dateTime } from '../lib/research'
import { uiProjectPath, useProjects } from '../model/queries'
import { ResearchError } from './ResearchError'
import { ResearchForm } from './ResearchForm'
import { ResearchGate } from './ResearchGate'

export function ResearchProjectsPage() {
  const { companyId } = useParams<{ companyId: string }>()
  if (companyId === undefined) return null
  return (
    <ResearchGate key={companyId} companyId={companyId}>
      <Projects companyId={companyId} />
    </ResearchGate>
  )
}
function Projects({ companyId }: { companyId: string }) {
  const [search, setSearch] = useSearchParams()
  const [creating, setCreating] = useState(false)
  const cursor = search.get('cursor')
  const query = useProjects(companyId, cursor)
  const navigate = useNavigate()
  return (
    <>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">Подбор товаров Ozon</h1>
          <p className="mt-1 text-sm text-text-secondary">
            Сезонные исследования категорий и помесячные срезы MPStats.
          </p>
        </div>
        <Button
          type="button"
          variant="primary"
          onClick={() => {
            setCreating(true)
          }}
        >
          Новый проект
        </Button>
      </div>
      {creating && (
        <ResearchForm
          companyId={companyId}
          mode="create"
          onCancel={() => {
            setCreating(false)
          }}
        />
      )}
      {query.isPending && <p role="status">Загружаем проекты…</p>}
      {query.isError && (
        <ResearchError
          error={query.error}
          retry={() => {
            void query.refetch()
          }}
        />
      )}
      {query.isSuccess && (
        <>
          {query.data.items.length === 0 && (
            <Card>
              <StatusPanel
                title="Исследований пока нет"
                description="Выберите категорию Ozon и периоды — например, один сезон за два года."
                icon={<FolderSearch aria-hidden="true" size={20} />}
                tone="neutral"
              />
            </Card>
          )}
          <div className="flex flex-col gap-3">
            {query.data.items.map((project) => (
              <Card key={project.id}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <Link
                      className="text-base font-semibold break-words"
                      to={uiProjectPath(companyId, project.id)}
                    >
                      {project.name}
                    </Link>
                    <p className="mt-1 text-sm break-words text-text-secondary">
                      {project.categoryPath}
                    </p>
                    <p className="mt-2 text-xs text-text-muted">
                      Периодов: {project.periodCount} · Создан{' '}
                      {dateTime(project.createdAt)}
                    </p>
                  </div>
                  <Badge
                    tone={
                      project.status === 'loaded'
                        ? 'positive'
                        : project.status === 'error'
                          ? 'negative'
                          : 'neutral'
                    }
                  >
                    {projectStatus(project.status)}
                  </Badge>
                </div>
              </Card>
            ))}
          </div>
          <div className="flex gap-2">
            {cursor !== null && (
              <Button
                type="button"
                variant="secondary"
                size="compact"
                onClick={() => {
                  void navigate(uiProjectPath(companyId))
                }}
              >
                К началу списка
              </Button>
            )}
            {typeof query.data.nextCursor === 'string' && (
              <Button
                type="button"
                variant="secondary"
                size="compact"
                onClick={() => {
                  setSearch({ cursor: query.data.nextCursor ?? '' })
                }}
              >
                Следующие проекты
              </Button>
            )}
          </div>
        </>
      )}
    </>
  )
}
