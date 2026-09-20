<?php

declare(strict_types=1);

use Karewan\KnRoute\Attributes\Route;
use Karewan\KnRoute\Dumper\RoutesDumper;
use Karewan\KnRoute\Exceptions\MethodNotAllowedException;
use Karewan\KnRoute\Exceptions\ResourceNotFoundException;
use Karewan\KnRoute\Router;

spl_autoload_register(static function (string $class): void {
	$prefix = 'Karewan\\KnRoute\\';
	if (str_starts_with($class, $prefix)) {
		require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	}
});

// Treat regexp warnings as failures, including prefix factoring and chunk retries.
set_error_handler(static fn(int $type, string $message) => throw new ErrorException($message, 0, $type));
$cacheFile = sys_get_temp_dir() . '/knroute_matching_' . bin2hex(random_bytes(8)) . '.php';

try {
	$routes = [];
	$cases = [];
	for ($i = 0; $i < 500; $i++) {
		$prefix = '/api/r' . sprintf('%04d', $i);
		$name = 'route' . $i;
		$routes[] = route($prefix . '/{id:uint}', $name);
		$cases[] = [$prefix . '/42', 'GET', $name, ['id' => '42']];
	}

	$routes[] = route('/users/{id:uint}/edit', 'edit');
	$routes[] = route('/users/{id:uint}/view', 'view');
	$routes[] = route('/users/{id:uint}/view', 'postView', ['POST']);
	$routes[] = route('/lookup/{id:uint}', 'lookupId');
	$routes[] = route('/lookup/{name:alpha}', 'lookupName');
	$routes[] = route('/lookup/{rest:path}', 'lookupAny', []);
	$routes[] = route('/lookup/new', 'lookupStatic');
	$routes[] = route('/symbols/{_knroute_uint:uint}/{_knroute_int:int}', 'symbolNames');
	$cases = array_merge($cases, [
		['/users/42/edit', 'GET', 'edit', ['id' => '42']],
		['/users/42/view', 'GET', 'view', ['id' => '42']],
		['/users/42/view', 'POST', 'postView', ['id' => '42']],
		['/lookup/42', 'GET', 'lookupId', ['id' => '42']],
		['/lookup/Alice', 'GET', 'lookupName', ['name' => 'Alice']],
		['/lookup/new', 'GET', 'lookupStatic', []],
		['/lookup/new', 'PATCH', 'lookupAny', ['rest' => 'new']],
		['/lookup/a/b', 'GET', 'lookupAny', ['rest' => 'a/b']],
		['/lookup/42', 'POST', 'lookupAny', ['rest' => '42']],
		['/symbols/42/-1', 'GET', 'symbolNames', ['_knroute_uint' => '42', '_knroute_int' => '-1']],
	]);

	// Compare captures against the standalone compiler, especially when backtracking
	// must split adjacent integers or preserve captures after an integer subroutine.
	$patterns = [
		'/mixed/{signed:int}/{unsigned:uint}/{word:alpha}' => [
			'/mixed/-42/17/Abc', '/mixed/' . PHP_INT_MIN . '/' . PHP_INT_MAX . '/Z',
			'/mixed/-0/1/A', '/mixed/1/01/A', '/mixed/1/99999999999999999999/A',
		],
		'/adjacent/{first:uint}{second:uint}' => [
			'/adjacent/12', '/adjacent/12345', '/adjacent/00', '/adjacent/01',
			'/adjacent/' . PHP_INT_MAX . PHP_INT_MAX, '/adjacent/1', '/adjacent/-12',
		],
		'/signed/{first:int}{second:int}' => [
			'/signed/-12-34', '/signed/1234', '/signed/' . PHP_INT_MIN . PHP_INT_MAX,
			'/signed/-0-0', '/signed/--1',
		],
		'/suffix/{id:uint}0' => ['/suffix/120', '/suffix/00', '/suffix/' . PHP_INT_MAX . '0', '/suffix/0'],
		'/plain/{name:alpha}/{id:uint}' => ['/plain/Alice/42', '/plain/Alice/00'],
	];
	$misses = ['/missing', '/users/99999999999999999999/view'];
	foreach ($patterns as $pattern => $paths) {
		$name = 'pattern' . count($routes);
		$route = route($pattern, $name);
		$routes[] = $route;
		foreach ($paths as $path) {
			if (!preg_match($route->compile()->getRegex(), $path, $matches)) {
				$misses[] = $path;
				continue;
			}
			$variables = [];
			foreach ($route->compile()->getPathVariables() as $variable) $variables[$variable] = $matches[$variable];
			$cases[] = [$path, 'GET', $name, $variables];
		}
	}

	$dumper = new RoutesDumper($routes);
	$compiled = $dumper->getCompiledRoutes();
	$sets = ['combined' => $compiled];
	// Force small blocks independently of platform-specific PCRE size limits.
	[, $dynamic] = (new ReflectionMethod(RoutesDumper::class, 'groupStaticRoutes'))->invoke($dumper);
	[$compiled[1], $compiled[2]] = (new ReflectionMethod(RoutesDumper::class, 'compileDynamicRoutes'))->invoke($dumper, $dynamic, 3);
	if (count($compiled[1]) < 2) throw new RuntimeException('Expected multiple regexp blocks');
	$sets['chunked'] = $compiled;

	foreach ($sets as $label => $compiled) {
		foreach (['memory', 'cache'] as $mode) {
			$router = new Router();
			if ($mode === 'memory') {
				(new ReflectionMethod(Router::class, 'setCompiledRoutes'))->invoke($router, $compiled);
			} else {
				$compiled[5] = (new ReflectionClass(Router::class))->getConstant('CACHE_FORMAT_VERSION');
				file_put_contents($cacheFile, '<?php return ' . RoutesDumper::dumpArray($compiled) . ';');
				if (function_exists('opcache_invalidate')) opcache_invalidate($cacheFile, true);
				$router->registerRoutesFromControllers(__DIR__ . '/not-scanned', $cacheFile);
			}
			$match = (new ReflectionMethod(Router::class, 'findRoute'))->getClosure($router);
			foreach ($cases as [$path, $method, $action, $variables]) {
				$result = $match($path, $method);
				if ($result[1] !== $action) throw new RuntimeException("{$label}/{$mode}: incorrect action for {$method} {$path}");
				unset($result[0], $result[1], $result[2]);
				if ($result !== $variables) throw new RuntimeException("{$label}/{$mode}: incorrect captures for {$path}");
			}
			foreach ($misses as $path) {
				try {
					$match($path, 'GET');
					throw new RuntimeException("{$label}/{$mode}: expected 404 for {$path}");
				} catch (ResourceNotFoundException) {
				}
			}
			try {
				$match('/users/42/view', 'DELETE');
				throw new RuntimeException("{$label}/{$mode}: expected 405");
			} catch (MethodNotAllowedException $e) {
				$methods = $e->getAllowedMethods();
				sort($methods);
				if ($methods !== ['GET', 'POST']) throw new RuntimeException('Incorrect Allow methods');
			}
		}
	}

	// Prefix pruning must still reject nested and variable-first intersections.
	foreach ([
		['/api/{rest:path}', '/api/items/{id:uint}'],
		['/{root:segment}/items/{id:uint}', '/api/items/{other:uint}'],
		['/api/items/{id:uint}', '/api/items/{other:int}'],
		['/api/static', '/api/static'],
	] as [$first, $second]) {
		foreach ([[route($first, 'first'), route($second, 'second')], [route($second, 'second'), route($first, 'first')]] as $conflicting) {
			try {
				new RoutesDumper($conflicting);
				throw new RuntimeException("Expected conflict for {$first} and {$second}");
			} catch (LogicException) {
			}
		}
	}

	// Popping a sibling prefix must not discard an ancestor that can still overlap.
	foreach (['/api/{rest:path}', '/{root:segment}/items/{id:uint}'] as $ancestor) {
		$conflicting = [
			route('/api/aaa/{id:uint}', 'sibling', ['POST']),
			route('/api/items/{id:uint}', 'descendant'),
			route('/z/{id:uint}', 'unrelated'),
			route($ancestor, 'ancestor'),
		];
		foreach (declarationOrders($conflicting) as $order) {
			try {
				new RoutesDumper($order);
				throw new RuntimeException('Ancestor conflict was lost when changing declaration order');
			} catch (LogicException) {
			}
		}
	}

	// Sorting/indexing may change compilation work, but not route precedence or output.
	$valid = [
		route('/api/items/new', 'newItem'),
		route('/api/items/{id:uint}', 'item'),
		route('/api/items/{name:alpha}', 'namedItem'),
		route('/api/items/{rest:path}', 'fallback', []),
		route('/api/item/{id:uint}', 'nearbyPrefix'),
		route('/{root:alpha}/index', 'variableFirst'),
		route('/api/items/{id:uint}', 'postItem', ['POST']),
	];
	$expected = (new RoutesDumper($valid))->getCompiledRoutes();
	foreach (declarationOrders($valid) as $order) {
		if ((new RoutesDumper($order))->getCompiledRoutes() !== $expected) {
			throw new RuntimeException('Compiled precedence depends on declaration order');
		}
	}

	echo "PASS  Large and chunked matchers preserve captures, integer bounds, method fallbacks and conflicts in memory and cache\n";
} finally {
	if (is_file($cacheFile)) unlink($cacheFile);
	restore_error_handler();
}

function route(string $path, string $action, array $methods = ['GET']): Route
{
	$route = new Route($methods, $path);
	$route->setAction(['MatchingController', $action]);
	return $route;
}

/** Exercise ancestors, descendants and siblings before and after one another. */
function declarationOrders(array $routes): iterable
{
	for ($i = 0, $count = count($routes); $i < $count; $i++) {
		$order = array_merge(array_slice($routes, $i), array_slice($routes, 0, $i));
		yield $order;
		yield array_reverse($order);
	}
}
