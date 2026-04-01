import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { App } from './App'


const container = document.getElementById('nhl-app')
if (container) {
    createRoot(container).render(
    <BrowserRouter>
        <App />
    </BrowserRouter>
    )
}
