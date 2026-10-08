import { AxiosError } from 'axios'
import {
  getOauthDeviceVerify,
  type PendingDeviceAuthorizationResource,
  postOauthDeviceApprove,
} from '@/shared/api-client/gen/endpoints'

/** Thin wrappers over the generated device endpoints, so the device page depends on one small interface. */

export type DeviceDecision = 'approve' | 'deny'

export type PendingDeviceAuthorization = PendingDeviceAuthorizationResource

/** Why the server refused a user code, from `error.details.reason` on a 400 answer. */
export type UserCodeErrorReason = 'user_code_required' | 'invalid_user_code' | 'device_already_processed'

const USER_CODE_ERROR_REASONS: readonly string[] = ['user_code_required', 'invalid_user_code', 'device_already_processed']

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isUserCodeErrorReason(value: unknown): value is UserCodeErrorReason {
  return typeof value === 'string' && USER_CODE_ERROR_REASONS.includes(value)
}

/** The reason a 400 answer gives for refusing the user code, or null for any other error. */
export function userCodeErrorReason(err: unknown): UserCodeErrorReason | null {
  if (!(err instanceof AxiosError) || err.response?.status !== 400) return null

  const body: unknown = err.response.data
  if (!isRecord(body) || !isRecord(body.error) || !isRecord(body.error.details)) return null

  const reason = body.error.details.reason
  return isUserCodeErrorReason(reason) ? reason : null
}

/** Looks up the pending request behind a user code. */
export async function lookupDeviceAuthorization(userCode: string, signal?: AbortSignal): Promise<PendingDeviceAuthorization> {
  const response = await getOauthDeviceVerify({ user_code: userCode }, { signal })
  return response.data
}

/** Records the decision and returns the one the server recorded. */
export async function decideDeviceAuthorization(userCode: string, decision: DeviceDecision): Promise<'approved' | 'denied'> {
  const response = await postOauthDeviceApprove({ userCode, decision })
  return response.data.decision
}
