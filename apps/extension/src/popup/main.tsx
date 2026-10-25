import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'

import { browserStorage } from '../shared/connection'
import { Popup } from './Popup'
import './index.css'

browserStorage()

const root = document.getElementById('root')
if (null === root) {
  throw new Error('Popup root element is missing.')
}

createRoot(root).render(
  <StrictMode>
    <Popup />
  </StrictMode>,
)
