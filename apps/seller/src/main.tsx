import { createRoot } from 'react-dom/client'
import { Root } from './app/Root'
import { bootstrapBrowserConfirmationToken } from './features/auth/lib/confirmationToken'
import './index.css'

bootstrapBrowserConfirmationToken(window.location, window.history)

const container = document.getElementById('root')
if (!container) {
  throw new Error('Root element #root not found')
}

createRoot(container).render(<Root />)
