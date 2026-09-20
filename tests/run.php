<?php

declare(strict_types=1);

const ROUTING_CONTROLLER = 'Tests\\Fixtures\\Controllers\\RoutingController';
const MIDDLEWARE_CONTROLLER = 'Tests\\Fixtures\\Controllers\\MiddlewareController';

$tests = [
	['GET matches a static route', 'GET', '/static', 200, 'static', ROUTING_CONTROLLER, 'staticRoute'],
	['GET matches a dynamic route', 'GET', '/users/42', 200, 'user:42', ROUTING_CONTROLLER, 'user'],
	['DELETE matches its route', 'DELETE', '/resources/7', 200, 'deleted:7', ROUTING_CONTROLLER, 'deleteResource'],
	['Any accepts arbitrary methods', 'PATCH', '/any', 200, 'any', ROUTING_CONTROLLER, 'anyMethod'],
	['Route accepts its first configured method', 'GET', '/multiple', 200, 'multiple', ROUTING_CONTROLLER, 'multipleMethods'],
	['Route accepts its second configured method', 'POST', '/multiple', 200, 'multiple', ROUTING_CONTROLLER, 'multipleMethods'],
	['Explicit HEAD matches its route without a body', 'HEAD', '/explicit-head', 200, '', ROUTING_CONTROLLER, 'explicitHead', [], ['cache-control', 'pragma', 'expires']],
	['Explicit OPTIONS matches its route', 'OPTIONS', '/explicit-options', 200, 'options', ROUTING_CONTROLLER, 'explicitOptions', ['allow' => 'OPTIONS', 'cache-control' => 'no-store']],
	['Numeric parameter is coerced to a typed integer', 'GET', '/typed/42', 200, 'int:42', ROUTING_CONTROLLER, 'typedInteger'],
	['Variable regex types accept valid values', 'GET', '/variables/a1/letters/a-slug/deadbeef/value', 200, 'a1|letters|a-slug|deadbeef|value', ROUTING_CONTROLLER, 'variableTypes'],
	['Variable regex types reject invalid values', 'GET', '/variables/a1/letters/not_ok/deadbeef/value', 404, '', null, null],
	['Catch-all variable accepts path separators', 'GET', '/files/path/to/file.txt', 200, 'file:path/to/file.txt', ROUTING_CONTROLLER, 'catchAll'],
	['Captured values are URL-decoded', 'GET', '/variables/a1/letters/a-slug/deadbeef/hello%20world', 200, 'a1|letters|a-slug|deadbeef|hello world', ROUTING_CONTROLLER, 'variableTypes'],
	['Query string does not affect route matching', 'GET', '/static?filter=test', 200, 'static', ROUTING_CONTROLLER, 'staticRoute'],
	['Routes are case-sensitive', 'GET', '/STATIC', 404, '', null, null],
	['Trailing slash is normalized', 'GET', '/static/', 200, 'static', ROUTING_CONTROLLER, 'staticRoute'],
	['First repeatable route attribute matches', 'GET', '/alias-one', 200, 'alias', ROUTING_CONTROLLER, 'aliases'],
	['Second repeatable route attribute matches', 'GET', '/alias-two', 200, 'alias', ROUTING_CONTROLLER, 'aliases'],
	['GET selects the correct action on a shared path', 'GET', '/method-specific', 200, 'get', ROUTING_CONTROLLER, 'methodSpecificGet'],
	['POST selects the correct action on a shared path', 'POST', '/method-specific', 200, 'post', ROUTING_CONTROLLER, 'methodSpecificPost'],
	['Explicit method takes priority over Any', 'GET', '/priority', 200, 'explicit', ROUTING_CONTROLLER, 'priorityGet'],
	['Any remains the fallback for other methods', 'PATCH', '/priority', 200, 'fallback', ROUTING_CONTROLLER, 'priorityFallback'],
	['Application-defined method is routed', 'PURGE', '/custom-method', 200, 'purged', ROUTING_CONTROLLER, 'customMethod'],
	['Application-defined method metadata is loaded from cache', 'PURGE', '/custom-method', 200, 'purged', ROUTING_CONTROLLER, 'customMethod', [], [], false, 'Controllers', true],
	['Class middleware runs before the controller', 'GET', '/middleware/class', 200, 'class>controller', MIDDLEWARE_CONTROLLER, 'classMiddleware'],
	['Class and method middleware run in order', 'GET', '/middleware/both', 200, 'class>method>controller', MIDDLEWARE_CONTROLLER, 'classAndMethodMiddlewares'],
	['Global middleware runs before a route', 'GET', '/static', 200, 'static', ROUTING_CONTROLLER, 'staticRoute', ['x-global-middleware' => 'true'], [], true],
	['Global middlewares run in declaration order', 'GET', '/static', 200, 'static', ROUTING_CONTROLLER, 'staticRoute', ['x-global-order' => 'first,second'], [], 2],
	['Global middleware runs before automatic OPTIONS', 'OPTIONS', '/static', 204, '', null, null, ['x-global-middleware' => 'true'], [], true],
	['Unknown path returns 404', 'GET', '/missing', 404, '', null, null],
	['Unsupported method returns 405', 'POST', '/static', 405, '', null, null, ['allow' => 'GET, HEAD, OPTIONS']],
	['Unknown method returns 501', 'BREW', '/static', 501, '', null, null, [], [], false, 'NoAny'],
	['Removed CONNECT method returns 501', 'CONNECT', '/static', 501, '', null, null, [], [], false, 'NoAny'],
	['Removed TRACE method returns 501', 'TRACE', '/static', 501, '', null, null, [], [], false, 'NoAny'],
	['OPTIONS is handled automatically', 'OPTIONS', '/static', 204, '', null, null, ['allow' => 'GET, HEAD, OPTIONS', 'cache-control' => 'no-store']],
	['OPTIONS bypasses Any routes', 'OPTIONS', '/any', 204, '', null, null, ['allow' => 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS, PURGE']],
	['OPTIONS asterisk describes server capabilities', 'OPTIONS', '*', 204, '', null, null, ['allow' => 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS, PURGE']],
	['HEAD recognizes GET without executing it', 'HEAD', '/static', 200, '', null, null, [], ['cache-control', 'pragma', 'expires']],
	['HEAD recognizes Any without executing it', 'HEAD', '/any', 200, '', null, null],
	['HEAD is rejected when GET is unavailable', 'HEAD', '/post-only', 405, '', null, null, ['allow' => 'POST, OPTIONS']]
];

$compilationTests = [
	['Duplicate routes are rejected', 'ConflictDuplicate', LogicException::class],
	['Equivalent dynamic routes are rejected', 'ConflictDynamic', LogicException::class],
	['Invalid HTTP method tokens are rejected', 'InvalidMethods', InvalidArgumentException::class]
];

$failures = 0;

foreach ($tests as $test) {
	[$name, $method, $uri, $expectedStatus, $expectedOutput, $expectedController, $expectedAction] = $test;
	$expectedHeaders = $test[7] ?? [];
	$unexpectedHeaders = $test[8] ?? [];
	$globalMiddlewareCount = (int) ($test[9] ?? 0);
	$fixtureDirectory = $test[10] ?? 'Controllers';
	$useCache = $test[11] ?? false;
	$result = runRouteRequest($method, $uri, $globalMiddlewareCount, $fixtureDirectory, $useCache);
	$errors = [];

	assertSame($expectedStatus, $result['status'], 'status', $errors);
	assertSame($expectedOutput, $result['output'], 'output', $errors);
	assertSame($expectedController, $result['controller'], 'controller', $errors);
	assertSame($expectedAction, $result['action'], 'action', $errors);
	assertSame(0, $result['exitCode'], 'exit code', $errors);
	foreach ($expectedHeaders as $header => $expectedValue) {
		assertSame($expectedValue, $result['headers'][$header] ?? null, "{$header} header", $errors);
	}
	foreach ($unexpectedHeaders as $header) {
		if (array_key_exists($header, $result['headers'])) {
			$errors[] = "{$header} header: expected it to be absent, got " . var_export($result['headers'][$header], true);
		}
	}

	if ($errors === []) {
		echo "PASS  {$name}\n";
		continue;
	}

	$failures++;
	echo "FAIL  {$name}\n";
	foreach ($errors as $error) {
		echo "      {$error}\n";
	}
	if ($result['diagnostics'] !== '') {
		echo "      stderr: {$result['diagnostics']}\n";
	}
}

foreach ($compilationTests as [$name, $fixtureDirectory, $expectedException]) {
	$result = runCompilationCheck($fixtureDirectory);
	if ($result['exitCode'] === 0 && $result['exception'] === $expectedException) {
		echo "PASS  {$name}\n";
		continue;
	}

	$failures++;
	echo "FAIL  {$name}\n";
	echo '      expected ' . $expectedException . ', got ' . var_export($result, true) . "\n";
}

echo sprintf("\n%d tests, %d failures\n", count($tests) + count($compilationTests), $failures);
exit($failures === 0 ? 0 : 1);

/**
 * @return array{status: int, output: string, controller: ?string, action: ?string, headers: array<string,string>, exitCode: int, diagnostics: string}
 */
function runRouteRequest(string $method, string $uri, int $globalMiddlewareCount = 0, string $fixtureDirectory = 'Controllers', bool $useCache = false): array
{
	$process = proc_open(
		[PHP_BINARY, __DIR__ . '/route_request.php', $method, $uri, (string) $globalMiddlewareCount, $fixtureDirectory, $useCache ? 'cache' : ''],
		[
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w']
		],
		$pipes,
		__DIR__ . '/..'
	);

	if (!is_resource($process)) {
		throw new RuntimeException('Unable to start the route request process.');
	}

	$output = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);

	$marker = '__ROUTER_METADATA__';
	$markerPosition = strrpos($stderr, $marker);
	if ($markerPosition === false) {
		throw new RuntimeException("Route request did not return metadata. STDERR: {$stderr}");
	}

	$diagnostics = trim(substr($stderr, 0, $markerPosition));
	$metadata = json_decode(substr($stderr, $markerPosition + strlen($marker)), true, flags: JSON_THROW_ON_ERROR);

	return [
		'status' => $metadata['status'],
		'output' => $output,
		'controller' => $metadata['controller'],
		'action' => $metadata['action'],
		'headers' => $metadata['headers'],
		'exitCode' => $exitCode,
		'diagnostics' => $diagnostics
	];
}

/**
 * @return array{exitCode: int, exception: ?string, message: ?string}
 */
function runCompilationCheck(string $fixtureDirectory): array
{
	$process = proc_open(
		[PHP_BINARY, __DIR__ . '/compile_routes.php', $fixtureDirectory],
		[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
		$pipes,
		__DIR__ . '/..'
	);

	if (!is_resource($process)) throw new RuntimeException('Unable to start the compilation check process.');
	$output = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);
	$data = $output !== '' ? json_decode($output, true, flags: JSON_THROW_ON_ERROR) : [];

	return [
		'exitCode' => $exitCode,
		'exception' => $data['class'] ?? null,
		'message' => $data['message'] ?? ($stderr !== '' ? trim($stderr) : null)
	];
}

function assertSame(mixed $expected, mixed $actual, string $field, array &$errors): void
{
	if ($expected !== $actual) {
		$errors[] = sprintf('%s: expected %s, got %s', $field, var_export($expected, true), var_export($actual, true));
	}
}
