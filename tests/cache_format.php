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

	$symbols = $cache[4] ?? [];
	assertSame(1, $cache[5] ?? null, 'cache format version');
	assertTrue(is_string($cache[6] ?? null), 'controller signature');
	assertTrue(is_string($cache[7] ?? null), 'controller quick signature');

	$middlewareAction = $cache[0]['/middleware/both'][0][0] ?? null;
	assertSame(MIDDLEWARE_CONTROLLER, $symbols[$middlewareAction[0] ?? -1] ?? null, 'cached middleware controller');
	assertSame('classAndMethodMiddlewares', $middlewareAction[1] ?? null, 'cached middleware method');
	assertSame([
		array_search('Tests\\Fixtures\\Middlewares\\ClassMiddleware', $symbols, true),
		array_search('Tests\\Fixtures\\Middlewares\\MethodMiddleware', $symbols, true),
	], $middlewareAction[2] ?? null, 'cached middleware construction plan');
	assertTrue(!array_key_exists(3, $middlewareAction), 'empty middleware route converters are omitted');

	$argumentAction = $cache[0]['/middleware/arguments'][0][0] ?? null;
	assertSame(Role::Admin, $argumentAction[2][1][1][0] ?? null, 'cached enum middleware argument');
	$cachedPolicy = $argumentAction[2][1][1][1] ?? null;
	assertTrue($cachedPolicy instanceof ExportablePolicy, 'cached exportable object middleware argument type');
	assertSame('managed', $cachedPolicy->name, 'cached exportable object middleware argument state');

	$combinedAction = findAction($cache[2] ?? [], $symbols, MIDDLEWARE_CONTROLLER, 'typedMiddleware');
	assertSame([
		[array_search('Tests\\Fixtures\\Middlewares\\ClassMiddleware', $symbols, true)],
		['id' => 0],
	], $combinedAction[2] ?? null, 'middlewares and converters share one compact metadata slot');

	try {
		RoutesDumper::dumpArray([new stdClass()]);
		throw new RuntimeException('non-exportable object cache value was accepted');
	} catch (LogicException $e) {
		assertTrue(str_contains($e->getMessage(), '__set_state'), 'non-exportable object rejection explains the requirement');
	}

	$typedAction = findAction($cache[2] ?? [], $symbols, ROUTING_CONTROLLER, 'typedInteger');
	assertTrue(is_array($typedAction), 'cached typed route action');
	assertSame(['id' => 0], $typedAction[2] ?? null, 'cached argument conversion plan');
	assertTrue(!array_key_exists(3, $typedAction), 'typed route uses compact metadata slot');

	$scalarAction = findAction($cache[2] ?? [], $symbols, ROUTING_CONTROLLER, 'typedScalars');
	assertTrue(is_array($scalarAction), 'cached scalar route action');
	assertSame([
		'integer' => 0,
		'decimal' => 1,
		'flag' => 2,
	], $scalarAction[2] ?? null, 'cached scalar argument conversion plan');

	$unionAction = findAction($cache[2] ?? [], $symbols, ROUTING_CONTROLLER, 'typedStringUnion');
	assertTrue(is_array($unionAction), 'cached string union route action');
	assertTrue(!array_key_exists(2, $unionAction), 'empty string-union metadata is omitted');

	$nullableAction = findAction($cache[2] ?? [], $symbols, ROUTING_CONTROLLER, 'typedNullableInteger');
	assertTrue(is_array($nullableAction), 'cached nullable scalar route action');
	assertSame(['value' => 0], $nullableAction[2] ?? null, 'cached nullable scalar conversion plan');

	$cache[5] = 2;
	file_put_contents($cacheFile, '<?php return ' . var_export($cache, true) . ';');
	(new Router())->registerRoutesFromControllers(__DIR__ . '/Fixtures/Controllers', $cacheFile, true);
	assertSame(1, (require $cacheFile)[5] ?? null, 'incompatible cache regeneration');

	(new Router())->registerRoutesFromControllers(__DIR__ . '/Fixtures/Controllers', $productionCacheFile, false);
	$productionCache = require $productionCacheFile;
	assertSame(1, $productionCache[5] ?? null, 'production cache format version');
	assertTrue(!array_key_exists(6, $productionCache), 'production cache content signature is omitted');
	assertTrue(!array_key_exists(7, $productionCache), 'production cache quick signature is omitted');

	echo "PASS  Cache format stores and refreshes execution metadata\n";
} finally {
	if (is_file($cacheFile)) unlink($cacheFile);
	if (is_file($productionCacheFile)) unlink($productionCacheFile);
}

function findAction(array $dynamicRoutes, array $symbols, string $controller, string $method): ?array
{
	foreach ($dynamicRoutes as $routes) {
		foreach ($routes as $compiledRoute) {
			if (!is_array($compiledRoute)) continue;
			$action = $compiledRoute[0] ?? null;
			if (($symbols[$action[0] ?? -1] ?? null) === $controller && ($action[1] ?? null) === $method) return $action;
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
