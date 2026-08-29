import { createRoot } from 'react-dom/client'
import { Root } from './app/Root'
import './index.css'

const container = document.getElementById('root')
if (!container) {
  throw new Error('Root element #root not found')
}

createRoot(container).render(<Root />)
