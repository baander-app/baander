<?php

declare(strict_types=1);

const LOAD_WEB_CLIENTS = 8;
const LOAD_WEB_PATH = '/api/albums/?limit=20';

/**
 * Web load for the load proof: from the load barrier until the run ends, a fixed number of
 * signed-in clients each request the album listing again as soon as their previous request
 * finishes. Every request's status and curl-measured latency is recorded.
 *
 * @var string $project
 * @var Doctrine\ORM\EntityManagerInterface $manager
 */
$owner = drillOwner($manager, $project, 'media-load-web');
await(static fn (): bool => is_file(ROOT . '/load-go'), 'load barrier', 1200);
$log = fopen(ROOT . '/web-latency.jsonl', 'wb') ?: throw new RuntimeException('Cannot record web latency.');
$multi = curl_multi_init();
$start = static function () use ($multi, $owner): void {
    $handle = curl_init(BASE . LOAD_WEB_PATH);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [...$owner->proofHeaders('GET', BASE . '/api/albums/'), 'Accept: application/json']]);
    curl_multi_add_handle($multi, $handle);
};
foreach (range(1, LOAD_WEB_CLIENTS) as $_) {
    $start();
}
$requests = 0;
$deadline = microtime(true) + 1500;
while (!is_file(ROOT . '/load-done') && microtime(true) < $deadline) {
    curl_multi_exec($multi, $running);
    while (($info = curl_multi_info_read($multi)) !== false) {
        $handle = $info['handle'];
        fwrite($log, json_encode(['t' => hrtime(true) / 1e9, 'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'curl' => $info['result'], 'seconds' => curl_getinfo($handle, CURLINFO_TOTAL_TIME)], JSON_THROW_ON_ERROR) . "\n");
        ++$requests;
        curl_multi_remove_handle($multi, $handle);
        $start();
    }
    curl_multi_select($multi, 0.05);
    clearstatcache(true, ROOT . '/load-done');
}
fclose($log);
$result = ['clients' => LOAD_WEB_CLIENTS, 'path' => LOAD_WEB_PATH, 'requests' => $requests];
