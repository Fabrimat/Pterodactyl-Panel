<?php

use Illuminate\Support\Str;
use NunoMaduro\Collision\Provider;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__ . '/../vendor/autoload.php';

// public/index.php defines this for a real request, and the admin layout prints
// the elapsed time from it in its footer. Tests never run that entry point, so
// without this every test that renders an admin page dies on an undefined
// constant rather than on anything to do with the page itself.
defined('LARAVEL_START') || define('LARAVEL_START', microtime(true));

$app = require __DIR__ . '/app.php';

/** @var Pterodactyl\Console\Kernel $kernel */
$kernel = $app->make(Kernel::class);

/*
 * Bootstrap the kernel and prepare application for testing.
 */
$kernel->bootstrap();

// Register the collision service provider so that errors during the test
// setup process are output nicely.
(new Provider())->register();

$output = new ConsoleOutput();

$prefix = 'database.connections.' . config('database.default');
if (!Str::contains(config("$prefix.database"), 'test')) {
    $output->writeln(PHP_EOL . '<error>Cannot run test process against non-testing database.</error>');
    $output->writeln(PHP_EOL . '<error>Environment is currently pointed at: "' . config("$prefix.database") . '".</error>');
    exit(1);
}

/*
 * Perform database migrations and reseeding before continuing with
 * running the tests.
 */
if (!env('SKIP_MIGRATIONS')) {
    $output->writeln(PHP_EOL . '<info>Refreshing database for Integration tests...</info>');
    $kernel->call('migrate:fresh');

    $output->writeln('<info>Seeding database for Integration tests...</info>' . PHP_EOL);
    $kernel->call('db:seed');
} else {
    $output->writeln(PHP_EOL . '<comment>Skipping database migrations...</comment>' . PHP_EOL);
}
