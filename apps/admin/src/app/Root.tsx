import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { createBrowserRouter, Navigate, RouterProvider } from 'react-router'

import { CreateAdministratorPage } from '../features/administrators/ui/CreateAdministratorPage'
import { LoginPage } from '../features/auth/ui/LoginPage'
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
  { path: '/', element: <Navigate to="/administrators" replace /> },
  { path: '/login', element: <LoginPage /> },
  {
    element: (
      <RequireAuth>
        <AdminLayout />
      </RequireAuth>
    ),
    children: [
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
