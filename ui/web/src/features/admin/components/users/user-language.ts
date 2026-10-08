import { SERVER_DEFAULT_SELECTION } from '@/features/settings/email-language'
import type { AdminUserSetting } from '../../api/user-admin-api'
import type { UserSettingChange } from '../../hooks/use-users'

/** What the select shows before the admin picks: the user's valid choice, otherwise the server default. */
export function initialLanguageSelection(setting: AdminUserSetting): string {
  return setting.storedValue !== null && setting.storedValueValid ? String(setting.storedValue) : SERVER_DEFAULT_SELECTION
}

/** The request that applies the admin's pick, or null when nothing would change. */
export function languageChange(userId: string, setting: AdminUserSetting, selection: string): UserSettingChange | null {
  if (selection === SERVER_DEFAULT_SELECTION) {
    return setting.storedValue === null ? null : { id: userId, key: setting.key, action: 'reset' }
  }
  if (selection === initialLanguageSelection(setting)) {
    return null
  }
  const option = setting.options.find((candidate) => String(candidate.value) === selection)

  return option ? { id: userId, key: setting.key, action: 'set', value: option.value } : null
}
