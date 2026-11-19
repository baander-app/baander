import {
  AdminCreateOAuthClientRequestAuthMethod,
  AdminCreateOAuthClientRequestType,
  type AdminOAuthClientResourceAuthMethod,
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

export type ConfidentialAuthMethod = NonNullable<AdminCreateOAuthClientRequestAuthMethod>

/** How a confidential client can authenticate; the method is fixed at registration. */
export const CONFIDENTIAL_AUTH_METHODS = Object.values(AdminCreateOAuthClientRequestAuthMethod)

export const AUTH_METHOD_LABELS: Record<AdminOAuthClientResourceAuthMethod, string> = {
  none: 'None',
  client_secret_post: 'Client secret',
  private_key_jwt: 'Signing keys',
}

export function isConfidentialAuthMethod(value: string): value is ConfidentialAuthMethod {
  return (CONFIDENTIAL_AUTH_METHODS as readonly string[]).includes(value)
}
