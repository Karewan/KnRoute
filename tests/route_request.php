<?php

declare(strict_types=1);

use Karewan\KnRoute\Router;

const PROJECT_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
	$prefixes = [
		'Karewan\\KnRoute\\' => PROJECT_ROOT . '/src/',
		'Tests\\Fixtures\\' => __DIR__ . '/Fixtures/'
	];

	foreach ($prefixes as $prefix => $directory) {
		if (!str_starts_with($class, $prefix)) {
			continue;
		}

		$file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require $file;
		}
		return;
	}
});

$_SERVER['REQUEST_METHOD'] = $argv[1] ?? 'GET';
$_SERVER['REQUEST_URI'] = $argv[2] ?? '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';

$router = new Router();

register_shutdown_function(static function () use ($router): void {
	$metadata = [
		'status' => http_response_code(),
		'controller' => $router->getFindedController(),
		'action' => $router->getFindedMethod()
	];

	fwrite(STDERR, '__ROUTER_METADATA__' . json_encode($metadata, JSON_THROW_ON_ERROR));
});

http_response_code(200);
$router->registerRoutesFromControllers(__DIR__ . '/Fixtures/Controllers', null);
$router->run();
