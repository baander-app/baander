import { apiOrigin, AXIOS_INSTANCE } from '@/shared/api-client/axios-instance'

interface TicketResponse {
  data: {
    ticket: string
    expiresAt: string
  }
}

export async function requestWebSocketTicket(signal: AbortSignal): Promise<string> {
  const response = await AXIOS_INSTANCE.post<TicketResponse>('/api/ws/tickets', {}, { signal })
  const receipt = response.data?.data
  if (!receipt || typeof receipt.ticket !== 'string' || receipt.ticket.length !== 64 || !/^[a-f0-9]{64}$/.test(receipt.ticket) ||
      typeof receipt.expiresAt !== 'string' || !Number.isFinite(Date.parse(receipt.expiresAt))) {
    throw new Error('Invalid WebSocket ticket response')
  }
  return receipt.ticket
}

export function webSocketEndpoint(): string {
  const url = new URL('/api/ws', apiOrigin())
  url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:'
  return url.href
}
