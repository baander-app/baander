import { ipcMain } from 'electron'

/**
 * Register playback IPC channels.
 * The actual handlers are in the menu system and globalShortcut registration.
 * This module ensures the channel namespace exists and any future
 * renderer→main playback requests can be routed here.
 */
export function registerPlaybackIpc(): void {
  // Forward playback actions via specific IPC channels (already done by menu system)
  // This registrar is a placeholder for future bidirectional playback IPC
}
