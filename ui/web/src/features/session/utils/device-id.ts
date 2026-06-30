const STORAGE_KEY = 'baander-device-id'
const NAME_STORAGE_KEY = 'baander-device-name'

function detectDeviceName(): string {
  const ua = navigator.userAgent
  let os = 'Unknown'
  if (ua.includes('Mac OS X')) os = 'macOS'
  else if (ua.includes('Windows')) os = 'Windows'
  else if (ua.includes('Linux')) os = 'Linux'
  else if (ua.includes('Android')) os = 'Android'
  else if (/iPhone|iPad/.test(ua)) os = 'iOS'

  let browser = 'Browser'
  if (ua.includes('Firefox/')) browser = 'Firefox'
  else if (ua.includes('Edg/')) browser = 'Edge'
  else if (ua.includes('Chrome/')) browser = 'Chrome'
  else if (ua.includes('Safari/') && !ua.includes('Chrome')) browser = 'Safari'

  return `${browser} on ${os}`
}

export function getDeviceId(): string {
  let id = localStorage.getItem(STORAGE_KEY)
  if (!id) {
    id = crypto.randomUUID()
    localStorage.setItem(STORAGE_KEY, id)
  }
  return id
}

export function getDeviceName(): string {
  let name = localStorage.getItem(NAME_STORAGE_KEY)
  if (!name) {
    name = detectDeviceName()
    localStorage.setItem(NAME_STORAGE_KEY, name)
  }
  return name
}
