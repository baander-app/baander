import { spawn } from 'node:child_process'

export const MANAGED_COMMAND_MS = 3_600_000
export const MANAGED_CLEANUP_MS = 120_000
export const RESTART_QUALIFICATION_MS = MANAGED_COMMAND_MS + 90_000 + 30_000 + 60_000
export const RECOVERY_TEARDOWN_MS = MANAGED_CLEANUP_MS + 90_000 + 30_000

export interface ManagedReceipt {
  command: string[]
  run: string
  pid?: number
  started: number
  deadline: number
  ended?: number
  code?: number | null
  signal?: NodeJS.Signals | null
  termination?: 'deadline' | 'teardown'
  closeConfirmed: boolean
}

interface ManagedOptions {
  cwd: string
  run: string
  signal: AbortSignal
  commandMs?: number
  cleanupMs?: number
}

export function startManagedLifecycle(arguments_: string[], options: ManagedOptions) {
  const started = Date.now()
  const child = spawn('php', ['scripts/dev-environment.php', ...arguments_], {
    cwd: options.cwd, stdio: ['ignore', 'pipe', 'pipe'],
  })
  const receipt: ManagedReceipt = {
    command: [...arguments_], run: options.run, pid: child.pid, started,
    deadline: started + (options.commandMs ?? MANAGED_COMMAND_MS),
    closeConfirmed: false,
  }
  let completedSuccessfully = false
  let cleanupUnconfirmed = false
  let output = ''
  const completion = new Promise<ManagedReceipt>((resolve, reject) => {
    let settled = false
    let spawnError: Error | undefined
    let cleanupTimer: ReturnType<typeof setTimeout> | undefined
    const fail = (message: string) => {
      if (!settled) {
        settled = true
        reject(new Error(message))
      }
    }
    const terminate = (reason: 'deadline' | 'teardown') => {
      if (receipt.closeConfirmed || receipt.termination) return
      receipt.termination = reason
      clearTimeout(deadlineTimer)
      child.kill('SIGTERM')
      cleanupTimer = setTimeout(() => {
        cleanupUnconfirmed = true
        options.signal.removeEventListener('abort', abort)
        fail(`Managed command ${arguments_[0]} cleanup is unconfirmed; retain its PID and fixture manifest`)
      }, options.cleanupMs ?? MANAGED_CLEANUP_MS)
    }
    const abort = () => terminate('teardown')
    const deadlineTimer = setTimeout(() => terminate('deadline'), options.commandMs ?? MANAGED_COMMAND_MS)
    options.signal.addEventListener('abort', abort, { once: true })
    child.stdout.on('data', (chunk: Buffer) => { output = (output + chunk.toString()).slice(-4000) })
    child.stderr.on('data', (chunk: Buffer) => { output = (output + chunk.toString()).slice(-4000) })
    child.once('error', (error) => { spawnError = error })
    child.once('close', (code, signal) => {
      clearTimeout(deadlineTimer)
      clearTimeout(cleanupTimer)
      options.signal.removeEventListener('abort', abort)
      Object.assign(receipt, { ended: Date.now(), code, signal, closeConfirmed: true })
      if (spawnError || receipt.termination || code !== 0) {
        fail(`Managed command ${arguments_[0]} failed (${code}): ${spawnError?.message ?? receipt.termination ?? output}`)
      } else if (!settled) {
        settled = true
        completedSuccessfully = true
        resolve(receipt)
      }
    })
    if (options.signal.aborted) abort()
  })
  return {
    receipt,
    completion,
    get canResumeFixture() {
      return completedSuccessfully
    },
    get canCleanupFixture() {
      return receipt.closeConfirmed && !cleanupUnconfirmed
    },
  }
}
