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
	['TRACE matches its route', 'TRACE', '/trace', 200, 'trace', ROUTING_CONTROLLER, 'trace'],
	['CONNECT matches its route', 'CONNECT', '/connect', 200, 'connect', ROUTING_CONTROLLER, 'connect'],
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
	['Class middleware runs before the controller', 'GET', '/middleware/class', 200, 'class>controller', MIDDLEWARE_CONTROLLER, 'classMiddleware'],
	['Class and method middleware run in order', 'GET', '/middleware/both', 200, 'class>method>controller', MIDDLEWARE_CONTROLLER, 'classAndMethodMiddlewares'],
	['Unknown path returns 404', 'GET', '/missing', 404, '', null, null],
	['Unsupported method returns 405', 'POST', '/static', 405, '', null, null, ['allow' => 'GET, HEAD, OPTIONS']],
	['OPTIONS is handled automatically', 'OPTIONS', '/static', 204, '', null, null, ['allow' => 'GET, HEAD, OPTIONS', 'cache-control' => 'no-store']],
	['OPTIONS bypasses Any routes', 'OPTIONS', '/any', 204, '', null, null, ['allow' => 'GET, HEAD, POST, PUT, PATCH, DELETE, CONNECT, OPTIONS, TRACE']],
	['OPTIONS asterisk describes server capabilities', 'OPTIONS', '*', 204, '', null, null, ['allow' => 'GET, HEAD, POST, PUT, PATCH, DELETE, CONNECT, OPTIONS, TRACE']],
	['HEAD falls back to GET without a body', 'HEAD', '/static', 200, '', ROUTING_CONTROLLER, 'staticRoute', [], ['cache-control', 'pragma', 'expires']],
	['HEAD is rejected when GET is unavailable', 'HEAD', '/post-only', 405, '', null, null, ['allow' => 'POST, OPTIONS']]
];

$failures = 0;

foreach ($tests as $test) {
	[$name, $method, $uri, $expectedStatus, $expectedOutput, $expectedController, $expectedAction] = $test;
	$expectedHeaders = $test[7] ?? [];
	$unexpectedHeaders = $test[8] ?? [];
	$result = runRouteRequest($method, $uri);
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

echo sprintf("\n%d tests, %d failures\n", count($tests), $failures);
exit($failures === 0 ? 0 : 1);

/**
 * @return array{status: int, output: string, controller: ?string, action: ?string, headers: array<string,string>, exitCode: int, diagnostics: string}
 */
function runRouteRequest(string $method, string $uri): array
{
	$process = proc_open(
		[PHP_BINARY, __DIR__ . '/route_request.php', $method, $uri],
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

function assertSame(mixed $expected, mixed $actual, string $field, array &$errors): void
{
	if ($expected !== $actual) {
		$errors[] = sprintf('%s: expected %s, got %s', $field, var_export($expected, true), var_export($actual, true));
	}
}
