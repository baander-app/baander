<?php

namespace App\Jobs\Library\Metadata;

use App\Models\Video;
use Baander\RedisStack\RedisStack;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

class ProbeQueueChecker implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $key = 'queue:probe';
            $redis = new RedisStack(Redis::connection('transcodes')->client());
            \Log::channel('stdout')->info($redis->getRedis()->getDBNum());
            $res = $redis->getRedis()->lPop($key);

            if (!$res) {
                return;
            }
            $res = json_decode($res, true);

            \Log::channel('stdout')->info('Paths', [
                'file_path' => $res['file_path'],
            ]);

            $res['file_path'] = str_replace('/mnt/c', '/storage', $res['file_path']);

            $video = Video::wherePath($res['file_path'])->first();
            if ($video) {
                $video->probe = $res;
                $video->save();
            }

            $this->delete();
        } catch (\Error|\Exception $e) {
            \Log::channel('stdout')->error('Failed to process video files', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
