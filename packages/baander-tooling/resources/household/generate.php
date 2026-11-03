<?php

declare(strict_types=1);

$plan = json_decode(base64_decode('HOUSEHOLD_PLAN_BASE64', true), true, flags: JSON_THROW_ON_ERROR);
$root = '/media/source/' . $plan['run'];
if (file_exists($root)) {
    throw new RuntimeException('Dataset destination already exists; retain its ownership manifest.');
}
if (!mkdir($root . '/.templates', 0755, true)) {
    throw new RuntimeException('Cannot create the dataset destination.');
}
file_put_contents($root . '/manifest.plan.json', json_encode($plan, JSON_THROW_ON_ERROR));

/** @param list<string> $arguments */
function command(array $arguments): string
{
    $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/tmp/household-generator.stderr', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot launch dataset preparation command.');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException('Dataset command failed: ' . implode(' ', $arguments) . ': ' . substr((string) file_get_contents('/tmp/household-generator.stderr'), -4000));
    }
    return $output === false ? '' : $output;
}

$templates = [];
$started = microtime(true);
foreach ($plan['files'] as &$file) {
    $name = $file['template'];
    $extension = pathinfo($file['path'], PATHINFO_EXTENSION);
    if (!isset($templates[$name])) {
        $template = $root . '/.templates/' . $name . '.' . $extension;
        $arguments = ['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-filter_threads', '1'];
        if ($file['videoCodec'] === null) {
            $arguments = [...$arguments, '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000', '-t', (string) $file['duration'], '-c:a', $file['audioCodec'], '-threads', '1'];
        } else {
            $arguments = [...$arguments, '-f', 'lavfi', '-i', 'color=c=black:size=320x180:rate=24'];
            if ($file['audioCodec'] !== null) {
                $arguments = [...$arguments, '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000'];
            }
            $arguments = [...$arguments, '-t', (string) $file['duration'], '-c:v', 'libx264', '-threads', '1', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-g', '24', '-sc_threshold', '0',
                '-colorspace', 'bt709', '-color_primaries', 'bt709', '-color_trc', 'bt709'];
            if ($name === 'video-irregular') {
                $arguments = [...$arguments, '-g', '240', '-force_key_frames', '0,1,3,7,12,20,31,45'];
            }
            if ($file['audioCodec'] !== null) {
                $arguments = [...$arguments, '-c:a', $file['audioCodec']];
            }
            if ($name === 'video-offset') {
                $arguments = [...$arguments, '-output_ts_offset', '7'];
            }
            if ($extension === 'mp4') {
                $arguments = [...$arguments, '-movflags', '+faststart'];
            }
        }
        command([...$arguments, '-fflags', '+bitexact', $template]);
        $templates[$name] = $template;
    }
    $path = '/media/source/' . $file['path'];
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) {
        throw new RuntimeException('Cannot create dataset media directory.');
    }
    $arguments = ['/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-y', '-i', $templates[$name], '-map', '0', '-c', 'copy', '-metadata', 'title=' . $file['title']];
    if ($file['kind'] === 'song') {
        $arguments = [...$arguments, '-metadata', 'album=' . $file['album'], '-metadata', 'artist=' . $file['artist'], '-metadata', 'album_artist=' . $file['artist'], '-metadata', 'track=' . $file['track']];
    }
    if ($extension === 'mp4') {
        $arguments = [...$arguments, '-movflags', '+faststart'];
    }
    if ($name === 'video-offset') {
        $arguments = [...$arguments, '-copyts', '-output_ts_offset', '0'];
    }
    command([...$arguments, '-fflags', '+bitexact', $path]);
    if ($extension === 'aac') {
        // ID3 frame lengths terminate these ASCII values. Avoid an extra NUL becoming part of a title.
        $frames = '';
        foreach (['TIT2' => $file['title'], 'TALB' => $file['album'], 'TPE1' => $file['artist'], 'TPE2' => $file['artist'], 'TRCK' => (string) $file['track']] as $key => $value) {
            $body = chr(0) . $value;
            $frames .= $key . pack('N', strlen($body)) . chr(0) . chr(0) . $body;
        }
        $size = strlen($frames);
        $header = 'ID3' . chr(3) . chr(0) . chr(0) . chr(($size >> 21) & 127) . chr(($size >> 14) & 127) . chr(($size >> 7) & 127) . chr($size & 127);
        file_put_contents($path, $header . $frames . file_get_contents($path));
    }
    chmod($path, 0644);
    if (isset($file['lyricsPath'])) {
        $lyricsPath = '/media/source/' . $file['lyricsPath'];
        file_put_contents($lyricsPath, '[00:00.00]' . $file['title'] . "\n");
        chmod($lyricsPath, 0644);
    }
    $probe = json_decode(command(['/usr/bin/ffprobe', '-v', 'error', '-show_entries', 'format=duration,size,start_time:stream=index,codec_name,codec_type,start_time,duration', '-of', 'json', $path]), true, flags: JSON_THROW_ON_ERROR);
    $file['expectedDuration'] = $file['duration'];
    $file['duration'] = (float) $probe['format']['duration'];
    $file['size'] = filesize($path);
    $file['hash'] = hash_file('xxh3', $path);
    $file['probe'] = $probe;
    $video = array_values(array_filter($probe['streams'], static fn(array $stream): bool => $stream['codec_type'] === 'video'));
    $audio = array_values(array_filter($probe['streams'], static fn(array $stream): bool => $stream['codec_type'] === 'audio'));
    if (($video[0]['codec_name'] ?? null) !== $file['videoCodec'] || ($audio[0]['codec_name'] ?? null) !== $file['audioCodec']) {
        throw new RuntimeException('Prepared media codec does not match its recipe.');
    }
}
unset($file);
if (count(array_unique(array_column($plan['files'], 'hash'))) !== count($plan['files'])) {
    throw new RuntimeException('Duplicate source hash would collapse scanned records.');
}
foreach ($templates as $template) {
    unlink($template);
}
rmdir($root . '/.templates');
$plan['preparation'] = ['startedAt' => $started, 'finishedAt' => microtime(true), 'bytes' => array_sum(array_column($plan['files'], 'size')),
    'durationDistribution' => array_count_values(array_map(static fn(array $file): string => (string) $file['expectedDuration'], $plan['files']))];
$json = json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
file_put_contents($root . '/manifest.json', $json);
chmod($root . '/manifest.json', 0644);
echo $json . "\n";
