// SPDX-License-Identifier: EUPL-1.2
// Client-side mirror of lib/Service/AutoUpdate/AutoUpdateWindow.php's format
// validation — HH:MM-HH:MM, used by the global auto-update settings row to
// give the admin immediate feedback before the server-side check runs.

export const AUTO_UPDATE_WINDOW_DEFAULT = '01:00-05:00'

const WINDOW_PATTERN = /^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-3]):([0-5]\d)$/

export const isValidAutoUpdateWindow = (window: string): boolean => WINDOW_PATTERN.test(window.trim())
