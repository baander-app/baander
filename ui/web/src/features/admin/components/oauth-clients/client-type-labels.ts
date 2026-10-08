import {
  AdminCreateOAuthClientRequestType,
  type AdminOAuthClientResourceType,
} from '@/shared/api-client/gen/endpoints'

export type CreatableClientType = AdminCreateOAuthClientRequestType

/** The types an administrator can register; the first-party client comes with the server. */
export const CREATABLE_CLIENT_TYPES = Object.values(AdminCreateOAuthClientRequestType)

export const CLIENT_TYPE_LABELS: Record<AdminOAuthClientResourceType, string> = {
  device: 'Device',
  public: 'Public',
  confidential: 'Confidential',
  first_party: 'First party',
}

export function isCreatableClientType(value: string): value is CreatableClientType {
  return (CREATABLE_CLIENT_TYPES as readonly string[]).includes(value)
}
