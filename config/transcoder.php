<?php

return [
  'ready_time_out' => env('TRANSCODE_READY_TIMEOUT', 10),
  'transcode_time_out' => env('TRANSCODE_TIMEOUT', 30),
  'log_file_path' => storage_path('logs/transcoder.log'),
];