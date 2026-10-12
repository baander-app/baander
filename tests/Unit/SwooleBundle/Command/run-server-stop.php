<?php

declare(strict_types=1);

/*
 * Runs swoole:server:stop for ServerStopCommandTest in a process of its own. The command's API
 * client runs coroutines, and with Xdebug loaded (as in CI's coverage run) Swoole's coroutine
 * runtime crashes when that process exits, so the test starts this script with Xdebug off.
 *
 * Arguments: the API server port and the shutdown timeout in seconds. Prints one JSON line with
 * the exit code and the command's output.
 */

use Symfony\Component\Console\Tester\CommandTester;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\Command\ServerStopCommand;
use SwooleBundle\SwooleBundle\Server\Api\ApiServerClientFactory;
use SwooleBundle\SwooleBundle\Server\Config\Socket;
use SwooleBundle\SwooleBundle\Server\Config\Sockets;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
$sockets = new Sockets(new Socket('127.0.0.1', 9501), new Socket('127.0.0.1', (int) $arguments[1]));
$tester = new CommandTester(new ServerStopCommand(new ApiServerClientFactory($sockets), (int) $arguments[2]));
$exitCode = $tester->execute([]);

echo json_encode(['exitCode' => $exitCode, 'display' => $tester->getDisplay()], JSON_THROW_ON_ERROR), "\n";
