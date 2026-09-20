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
		if (!str_starts_with($class, $prefix)) continue;
		$file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) require $file;
		return;
	}
});

$router = new Router();
$router->registerRoutesFromControllers(__DIR__ . '/Fixtures/Controllers', null);

foreach ([
	['OPTIONS', '/static', 'automatic response'],
	['GET', '/missing', 'unknown route'],
	['TRACE', '/static', 'unsupported method'],
] as [$method, $path, $description]) {
	request($router, 'GET', '/static');
	assertMatched($router, Tests\Fixtures\Controllers\RoutingController::class, 'staticRoute', 'matched route');
	request($router, $method, $path);
	assertMatched($router, null, null, $description);
}

echo "PASS  Router reuse clears matched route metadata\n";

function request(Router $router, string $method, string $path): void
{
	$_SERVER['REQUEST_METHOD'] = $method;
	$_SERVER['REQUEST_URI'] = $path;
	$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
	ob_start();
	try {
		$router->run();
	} finally {
		ob_end_clean();
	}
}

function assertMatched(Router $router, ?string $controller, ?string $method, string $description): void
{
	if ($router->getMatchedController() !== $controller || $router->getMatchedMethod() !== $method) {
		fwrite(STDERR, "FAIL  {$description}: stale matched route metadata\n");
		exit(1);
	}
}
