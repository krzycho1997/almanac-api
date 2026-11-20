<?php

declare(strict_types=1);

$server = stream_socket_server('tcp://127.0.0.1:0');

if (false === $server) {
    exit(1);
}

stream_set_blocking($server, false);
fwrite(\STDOUT, stream_socket_get_name($server, false).\PHP_EOL);

/** @var array<int, resource> $connections */
$connections = [];
/** @var array<int, string> $requests */
$requests = [];
/** @var array<int, array{float, int}> $answers */
$answers = [];
/** @var array<string, int> $attempts */
$attempts = [];

while (is_resource($server)) {
    $readable = [$server, ...array_values($connections)];
    $none = null;
    @stream_select($readable, $none, $none, 0, 5000);

    foreach ($readable as $socket) {
        if ($socket === $server) {
            $connection = @stream_socket_accept($server, 0);

            if (is_resource($connection)) {
                stream_set_blocking($connection, false);
                $connections[(int) $connection] = $connection;
                $requests[(int) $connection] = '';
            }

            continue;
        }

        $id = (int) $socket;
        $data = (string) @fread($socket, 8192);

        if ('' === $data) {
            if (feof($socket)) {
                fclose($socket);
                unset($connections[$id], $requests[$id], $answers[$id]);
            }

            continue;
        }

        $requests[$id] .= $data;

        if (str_contains($requests[$id], "\r\n\r\n")) {
            preg_match('#^GET (\S+)#', $requests[$id], $match);
            parse_str((string) parse_url($match[1] ?? '/', \PHP_URL_QUERY), $query);
            $key = is_string($query['key'] ?? null) ? $query['key'] : 'default';
            $failures = is_string($query['fail'] ?? null) ? (int) $query['fail'] : 0;
            $pause = is_string($query['sleep'] ?? null) ? (float) $query['sleep'] : 0.0;
            $attempts[$key] = ($attempts[$key] ?? 0) + 1;
            $answers[$id] = [microtime(true) + $pause, $attempts[$key] <= $failures ? 503 : 200];
            $requests[$id] = '';
        }
    }

    foreach ($answers as $id => [$due, $status]) {
        if ($due > microtime(true)) {
            continue;
        }

        $body = '{}';
        stream_set_blocking($connections[$id], true);
        @fwrite($connections[$id], "HTTP/1.1 {$status} Answer\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\n\r\n{$body}");
        stream_set_blocking($connections[$id], false);
        unset($answers[$id]);
    }
}
