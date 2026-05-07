<?php

declare(strict_types=1);

use App\Core\Request;

require dirname(__DIR__) . '/bootstrap/app.php';

$request = Request::capture();
$response = $router->dispatch($request);
$response->send();

