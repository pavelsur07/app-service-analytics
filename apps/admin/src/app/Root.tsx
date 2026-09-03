import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createBrowserRouter, Navigate, RouterProvider } from 'react-router'

import { AccountsPage } from '../features/accounts/ui/AccountsPage'
import { CreateAdministratorPage } from '../features/administrators/ui/CreateAdministratorPage'
import { LoginPage } from '../features/auth/ui/LoginPage'
import { LinksPage } from '../features/links/ui/LinksPage'
import { AdminLayout } from './AdminLayout'
import { RequireAuth } from './RequireAuth'

// retry: false — TanStack Query по умолчанию повторяет неудачный запрос
// трижды; для 401/403 это не транзиентный сбой, а ответ, который
// не изменится, и повторы только откладывают редирект на секунды.
const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false } },
})

const router = createBrowserRouter([
  // Ping-экран, с которого начиналась заглушка админки, убран — той же
  // причины, что у продавца: продуктовой ценности не имеет, а занимал
  // единственный адрес, который человек набирает руками. Эндпоинт
  // /api/admin/ping остаётся, на нём висит smoke-проверка выкладки.
  // Корень ведёт к аккаунтам: это повседневная работа контура,
  // а заведение администраторов — редкое действие верхней роли.
  { path: '/', element: <Navigate to="/accounts" replace /> },
  { path: '/login', element: <LoginPage /> },
  {
    element: (
      <RequireAuth>
        <AdminLayout />
      </RequireAuth>
    ),
    children: [
      { path: '/accounts', element: <AccountsPage /> },
      { path: '/links', element: <LinksPage /> },
      { path: '/administrators', element: <CreateAdministratorPage /> },
    ],
  },
])

// Роутер и провайдеры (docs/structure.md, «app/»).
export function Root() {
  return (
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>
  )
}
