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

  // Discovery channels
  DISCOVERY_START_SCAN: 'baander:discovery:start-scan',
  DISCOVERY_STOP_SCAN: 'baander:discovery:stop-scan',
  DISCOVERY_GET_SERVERS: 'baander:discovery:get-servers',
  DISCOVERY_IS_SCANNING: 'baander:discovery:is-scanning',
  DISCOVERY_SERVER_FOUND: 'baander:discovery:server-found',
  DISCOVERY_SCAN_STOPPED: 'baander:discovery:scan-stopped',
  DISCOVERY_SCAN_STARTED: 'baander:discovery:scan-started',

  // AAC codec channels (native baander-aac binding in the main process)
  AAC_INFO: 'baander:aac:info',
  AAC_DECODE: 'baander:aac:decode',
  AAC_ENCODE: 'baander:aac:encode',
  AAC_DECODER_OPEN: 'baander:aac:decoder-open',
  AAC_DECODER_DECODE: 'baander:aac:decoder-decode',
  AAC_DECODER_CLOSE: 'baander:aac:decoder-close',
  AAC_ENCODER_OPEN: 'baander:aac:encoder-open',
  AAC_ENCODER_ENCODE: 'baander:aac:encoder-encode',
  AAC_ENCODER_FLUSH: 'baander:aac:encoder-flush',
  AAC_ENCODER_LOUDNESS: 'baander:aac:encoder-loudness',
  AAC_ENCODER_CLOSE: 'baander:aac:encoder-close',
  AAC_MEASURE_LOUDNESS: 'baander:aac:measure-loudness',
} as const;
