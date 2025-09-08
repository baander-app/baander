export const IPC_CHANNELS = {
  // Config channels
  CONFIG_GET_SERVER_URL: 'baander:config:get-server-url',
  CONFIG_SET_SERVER_URL: 'baander:config:set-server-url',
  CONFIG_GET_USER: 'baander:config:get-user',
  CONFIG_SET_USER: 'baander:config:set-user',
  CONFIG_CLEAR_USER: 'baander:config:clear-user',
  CONFIG_FINISH: 'baander:config:finish',

  // Deep link channels
  DEEPLINK_GET_PENDING_URL: 'baander:deeplink:get-pending-url',
  DEEPLINK_CLEAR_PENDING_URL: 'baander:deeplink:clear-pending-url',
  DEEPLINK_RECEIVED: 'baander:deeplink:received',
} as const;
