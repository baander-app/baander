import {
  type DeviceApproveRequestDecision,
  getOauthDeviceVerify,
  postOauthDeviceApprove,
} from '@/shared/api-client/gen/endpoints'

/** Thin wrappers over the generated device endpoints, so the device page depends on one small interface. */

export type DeviceDecision = DeviceApproveRequestDecision

export interface PendingDeviceAuthorization {
  userCode: string
  clientName: string
  scopes: string[]
}

/** Looks up the pending request behind a user code. A 400 means unknown, expired, or already decided. */
export async function lookupDeviceAuthorization(userCode: string, signal?: AbortSignal): Promise<PendingDeviceAuthorization> {
  const { data } = await getOauthDeviceVerify({ user_code: userCode }, { signal })

  return {
    userCode: data.userCode,
    clientName: data.clientName,
    scopes: data.scopes,
  }
}

export async function decideDeviceAuthorization(userCode: string, decision: DeviceDecision): Promise<void> {
  await postOauthDeviceApprove({ userCode, decision })
}
