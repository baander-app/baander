import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '@fontsource/inter'
import '@fontsource/kanit'
import '@fontsource/noto-sans-jp'
import '@fontsource/pretendard'
import '@fontsource/jetbrains-mono'
import './index.css'
import './shared/i18n'
import { initServiceWorkerListener, initWorkerApiUrl } from '@/features/player/services/service-worker-bridge'
import { registerAllHandlers } from '@/shared/lib/mediator/handlers'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { createLogger } from '@/shared/lib/logger'
import App from './App'

const logger = createLogger('Bootstrap')

async function bootstrap() {
  await useAuthStore.getState().initAuth()

  registerAllHandlers()
  initServiceWorkerListener()

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/auth-stream-worker.js').then(() => {
      return initWorkerApiUrl()
    }).catch((err) => {
      logger.warn('Service worker registration failed:', err)
    })
  }

  const rootEl = document.getElementById('root') || document.getElementById('baanderapproot')
  if (!rootEl) throw new Error('Root element not found')
  createRoot(rootEl).render(
    <StrictMode>
      <App />
    </StrictMode>,
  )
}

bootstrap()
