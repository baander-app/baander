<?php

declare(strict_types=1);

// No application bootstrap or inherited credentials. SIGALRM also bounds orphaned lookups.
if ($argc !== 3 || !preg_match('/\A[a-z0-9.-]{1,253}\z/iD', $argv[1])
    || !ctype_digit($argv[2]) || (int) $argv[2] < 1 || (int) $argv[2] > 30) {
    exit(2);
}
pcntl_signal(SIGALRM, SIG_DFL);
pcntl_alarm((int) $argv[2]);
$records = @dns_get_record($argv[1], DNS_A | DNS_AAAA);
if ($records === false) {
    exit(2);
}
$addresses = [];
foreach ($records as $record) {
    if (isset($record['ip'])) {
        $addresses[] = $record['ip'];
    } elseif (isset($record['ipv6'])) {
        $addresses[] = $record['ipv6'];
    }
}
$reply = json_encode($addresses, JSON_THROW_ON_ERROR);
if (strlen($reply) > 65_536) {
    exit(2);
}
fwrite(STDOUT, $reply);
