<?php

declare(strict_types=1);

use Karewan\KnRoute\Router;
use Karewan\KnRoute\Dumper\RoutesDumper;
use Tests\Fixtures\Values\ExportablePolicy;
use Tests\Fixtures\Values\Role;

const PROJECT_ROOT = __DIR__ . '/..';
const ROUTING_CONTROLLER = 'Tests\\Fixtures\\Controllers\\RoutingController';
const MIDDLEWARE_CONTROLLER = 'Tests\\Fixtures\\Controllers\\MiddlewareController';

spl_autoload_register(static function (string $class): void {
	$prefixes = [
		'Karewan\\KnRoute\\' => PROJECT_ROOT . '/src/',
		'Tests\\Fixtures\\' => __DIR__ . '/Fixtures/',
	];

	foreach ($prefixes as $prefix => $directory) {
		if (!str_starts_with($class, $prefix)) continue;
		$file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) require $file;
		return;
	}
});

$cacheFile = sys_get_temp_dir() . '/knroute_cache_format_' . bin2hex(random_bytes(8)) . '.php';
$productionCacheFile = sys_get_temp_dir() . '/knroute_production_cache_format_' . bin2hex(random_bytes(8)) . '.php';

try {
	(new Router())->registerRoutesFromControllers(__DIR__ . '/Fixtures/Controllers', $cacheFile, true);
	$cache = require $cacheFile;

	assertSame(4, $cache[4] ?? null, 'cache format version');
	assertTrue(is_string($cache[5] ?? null), 'controller signature');
	assertTrue(is_string($cache[6] ?? null), 'controller quick signature');

	$middlewareAction = $cache[0]['/middleware/both'][0][0] ?? null;
	assertSame(MIDDLEWARE_CONTROLLER, $middlewareAction[0] ?? null, 'cached middleware controller');
	assertSame('classAndMethodMiddlewares', $middlewareAction[1] ?? null, 'cached middleware method');
	assertSame([
		['Tests\\Fixtures\\Middlewares\\ClassMiddleware', []],
		['Tests\\Fixtures\\Middlewares\\MethodMiddleware', []],
	], $middlewareAction[2] ?? null, 'cached middleware construction plan');
	assertSame([], $middlewareAction[3] ?? null, 'cached middleware route converters');

	$argumentAction = $cache[0]['/middleware/arguments'][0][0] ?? null;
	assertSame(Role::Admin, $argumentAction[2][1][1][0] ?? null, 'cached enum middleware argument');
	$cachedPolicy = $argumentAction[2][1][1][1] ?? null;
	assertTrue($cachedPolicy instanceof ExportablePolicy, 'cached exportable object middleware argument type');
	assertSame('managed', $cachedPolicy->name, 'cached exportable object middleware argument state');

	try {
		RoutesDumper::dumpArray([new stdClass()]);
		throw new RuntimeException('non-exportable object cache value was accepted');
	} catch (LogicException $e) {
		assertTrue(str_contains($e->getMessage(), '__set_state'), 'non-exportable object rejection explains the requirement');
	}

	$typedAction = findAction($cache[2] ?? [], ROUTING_CONTROLLER, 'typedInteger');
	assertTrue(is_array($typedAction), 'cached typed route action');
	assertSame([], $typedAction[2] ?? null, 'cached typed route middlewares');
	assertSame(['id' => 'int'], $typedAction[3] ?? null, 'cached argument conversion plan');

	$cache[4] = 1;
	file_put_contents($cacheFile, '<?php return ' . var_export($cache, true) . ';');
	(new Router())->registerRoutesFromControllers(__DIR__ . '/Fixtures/Controllers', $cacheFile, true);
	assertSame(4, (require $cacheFile)[4] ?? null, 'legacy cache regeneration');

	(new Router())->registerRoutesFromControllers(__DIR__ . '/Fixtures/Controllers', $productionCacheFile, false);
	$productionCache = require $productionCacheFile;
	assertSame(4, $productionCache[4] ?? null, 'production cache format version');
	assertTrue(!array_key_exists(5, $productionCache), 'production cache content signature is omitted');
	assertTrue(!array_key_exists(6, $productionCache), 'production cache quick signature is omitted');

	echo "PASS  Cache format stores and refreshes execution metadata\n";
} finally {
	if (is_file($cacheFile)) unlink($cacheFile);
	if (is_file($productionCacheFile)) unlink($productionCacheFile);
}

function findAction(array $dynamicRoutes, string $controller, string $method): ?array
{
	foreach ($dynamicRoutes as $routes) {
		foreach ($routes as $compiledRoute) {
			if (!is_array($compiledRoute)) continue;
			$action = $compiledRoute[0] ?? null;
			if (($action[0] ?? null) === $controller && ($action[1] ?? null) === $method) return $action;
		}
	}

	return null;
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
	if ($actual !== $expected) {
		throw new RuntimeException(sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true)));
	}
}

function assertTrue(bool $condition, string $label): void
{
	if (!$condition) throw new RuntimeException("{$label}: assertion failed");
}
