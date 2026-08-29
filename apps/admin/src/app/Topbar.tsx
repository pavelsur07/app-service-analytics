import { Badge } from '../../../../packages/ui/src'
import { useCurrentAdmin } from '../shared/model/useCurrentAdmin'

export function Topbar() {
  const currentAdmin = useCurrentAdmin()
  const email = currentAdmin.data?.email

  return (
    <header className="flex h-14 items-center gap-4 border-b border-border-default bg-surface-raised px-6">
      <span
        aria-hidden="true"
        className="grid size-7 place-items-center rounded-md bg-accent-default text-sm font-bold text-text-inverse"
      >
        C
      </span>

      <span className="font-semibold">Администрирование</span>

      {currentAdmin.data && (
        <Badge>
          {currentAdmin.data.role === 'super_admin' ? 'SuperAdmin' : 'Admin'}
        </Badge>
      )}

      <span
        className="ml-auto grid size-8 place-items-center rounded-full bg-accent-subtle text-xs font-semibold text-accent-hover"
        title={email}
      >
        {(email ?? '?').slice(0, 2).toUpperCase()}
      </span>
    </header>
  )
}
