import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import '@fontsource-variable/geist'
import '@fontsource-variable/geist-mono'
import '@fontsource-variable/noto-sans'
import '@fontsource-variable/noto-sans-thai'
import '@fontsource-variable/noto-sans-myanmar'
import '@fontsource-variable/noto-sans-sc'
import '@fontsource-variable/noto-sans-tc'
import '@fontsource-variable/noto-sans-jp'
import '@fontsource-variable/noto-sans-kr'
import '@fontsource-variable/noto-sans-mono'
import './index.css'
import './shared/i18n'
import { initServiceWorkerListener, initWorkerApiUrl } from '@/features/player/services/service-worker-bridge'
import { registerAllHandlers } from '@/shared/lib/mediator/handlers'
import { useAuthStore } from '@/features/auth/stores/auth-store'
import { drainPendingLogouts, installPendingLogoutTriggers } from '@/features/auth/services/pending-logout'
import { createLogger } from '@/shared/lib/logger'
import { loadPrimaryFonts } from '@/shared/typography'
import App from './App'

const logger = createLogger('Bootstrap')

async function bootstrap() {
  const primaryFonts = loadPrimaryFonts(document.fonts)
  await useAuthStore.getState().initAuth()
  // Logouts recorded earlier are delivered now and on every later trigger; the page lives as long as the app.
  installPendingLogoutTriggers()
  drainPendingLogouts()

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
  await primaryFonts
  createRoot(rootEl).render(
    <StrictMode>
      <App />
    </StrictMode>,
  )
}

bootstrap()
