import { createRoot } from 'react-dom/client'
import { App } from './App'

const container = document.getElementById('nhl-app')
if (container) {
    createRoot(container).render(<App />)
}
