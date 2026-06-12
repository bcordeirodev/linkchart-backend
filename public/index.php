<?php

// Request start time. Used to measure server response time for click tracking
// (RedirectController). Without this, the fallback makes response_time 0 for
// every real click, leaving the device-performance analytics empty.
define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

$response->send();

$kernel->terminate($request, $response);
