<?php

declare(strict_types=1);

const ROUTING_CONTROLLER = 'Tests\\Fixtures\\Controllers\\RoutingController';
const MIDDLEWARE_CONTROLLER = 'Tests\\Fixtures\\Controllers\\MiddlewareController';
const CONSTRUCTION_CONTROLLER = 'Tests\\Fixtures\\MiddlewareConstruction\\Controller';
const STOP_CONTROLLER = 'Tests\\Fixtures\\StopRequest\\Controller';
const STOP_BARE_CONTROLLER = 'Tests\\Fixtures\\StopRequest\\BareController';

$aboveIntegerMaximum = incrementDecimal((string) PHP_INT_MAX);
$belowIntegerMinimum = '-' . incrementDecimal(substr((string) PHP_INT_MIN, 1));

$tests = [
	['GET matches a static route', 'GET', '/static', 200, 'static', ROUTING_CONTROLLER, 'staticRoute'],
	['GET matches a dynamic route', 'GET', '/users/42', 200, 'user:42', ROUTING_CONTROLLER, 'user'],
	['Disjoint uint route remains valid', 'GET', '/lookup/42', 200, 'lookup-id:42', ROUTING_CONTROLLER, 'lookupById'],
	['Disjoint alpha route remains valid', 'GET', '/lookup/Alice', 200, 'lookup-name:Alice', ROUTING_CONTROLLER, 'lookupByName'],
	['DELETE matches its route', 'DELETE', '/resources/7', 200, 'deleted:7', ROUTING_CONTROLLER, 'deleteResource'],
	['Any accepts arbitrary methods', 'PATCH', '/any', 200, 'any', ROUTING_CONTROLLER, 'anyMethod'],
	['Route accepts its first configured method', 'GET', '/multiple', 200, 'multiple', ROUTING_CONTROLLER, 'multipleMethods'],
	['Route accepts its second configured method', 'POST', '/multiple', 200, 'multiple', ROUTING_CONTROLLER, 'multipleMethods'],
	['Explicit HEAD matches its route without a body', 'HEAD', '/explicit-head', 200, '', ROUTING_CONTROLLER, 'explicitHead', [], ['cache-control', 'pragma', 'expires']],
	['Explicit OPTIONS matches its route', 'OPTIONS', '/explicit-options', 200, 'options', ROUTING_CONTROLLER, 'explicitOptions', ['allow' => 'OPTIONS', 'cache-control' => 'no-store']],
	['Numeric parameter is coerced to a typed integer', 'GET', '/typed/42', 200, 'int:42', ROUTING_CONTROLLER, 'typedInteger'],
	['Controller scalar types drive compiled argument casts', 'GET', '/typed-scalars/-42/3.5/0/text/raw', 200, 'int:-42|float:3.5|bool:false|string:text|string:raw', ROUTING_CONTROLLER, 'typedScalars'],
	['A string-compatible union preserves the URL string', 'GET', '/typed-union/42', 200, 'string:42', ROUTING_CONTROLLER, 'typedStringUnion'],
	['A nullable scalar uses its unambiguous compiled cast', 'GET', '/typed-nullable/-42', 200, 'int:-42', ROUTING_CONTROLLER, 'typedNullableInteger'],
	['int accepts the platform maximum', 'GET', '/bounded-int/' . PHP_INT_MAX, 200, 'int:' . PHP_INT_MAX, ROUTING_CONTROLLER, 'boundedInteger'],
	['int accepts the platform minimum', 'GET', '/bounded-int/' . PHP_INT_MIN, 200, 'int:' . PHP_INT_MIN, ROUTING_CONTROLLER, 'boundedInteger'],
	['uint accepts the platform maximum', 'GET', '/bounded-uint/' . PHP_INT_MAX, 200, 'uint:' . PHP_INT_MAX, ROUTING_CONTROLLER, 'boundedUnsignedInteger'],
	['int rejects overflow above the platform maximum', 'GET', '/bounded-int/' . $aboveIntegerMaximum, 404, '', null, null],
	['int rejects overflow below the platform minimum', 'GET', '/bounded-int/' . $belowIntegerMinimum, 404, '', null, null],
	['uint rejects overflow above the platform maximum', 'GET', '/bounded-uint/' . $aboveIntegerMaximum, 404, '', null, null],
	['Variable regex types accept valid values', 'GET', '/variables/Alpha/letters/a-slug/DeadBeef/value', 200, 'Alpha|letters|a-slug|DeadBeef|value', ROUTING_CONTROLLER, 'variableTypes'],
	['Variable regex types reject invalid values', 'GET', '/variables/Alpha/letters/not_ok/deadbeef/value', 404, '', null, null],
	['Strict variable types accept valid values', 'GET', '/strict-variables/A1b2/-42/42/550e8400-e29b-41d4-a716-446655440000/a+b/path/to/file', 200, 'A1b2|-42|42|550e8400-e29b-41d4-a716-446655440000|a+b|path/to/file', ROUTING_CONTROLLER, 'strictVariableTypes'],
	['Canonical integers reject negative zero', 'GET', '/strict-variables/A1b2/-0/42/550e8400-e29b-41d4-a716-446655440000/value/path', 404, '', null, null],
	['Canonical unsigned integers reject leading zeroes', 'GET', '/strict-variables/A1b2/-42/042/550e8400-e29b-41d4-a716-446655440000/value/path', 404, '', null, null],
	['Alpha variables reject digits', 'GET', '/variables/a1/letters/a-slug/deadbeef/value', 404, '', null, null],
	['Slugs reject consecutive hyphens', 'GET', '/variables/Alpha/letters/a--slug/deadbeef/value', 404, '', null, null],
	['Catch-all variable accepts path separators', 'GET', '/files/path/to/file.txt', 200, 'file:path/to/file.txt', ROUTING_CONTROLLER, 'catchAll'],
	['Catch-all variable accepts encoded path separators', 'GET', '/files/foo%2Fbar', 200, 'file:foo/bar', ROUTING_CONTROLLER, 'catchAll'],
	['Segment variables reject uppercase encoded path separators', 'GET', '/segment-files/foo%2Fbar', 404, '', null, null],
	['Segment variables reject lowercase encoded path separators', 'GET', '/segment-files/foo%2fbar', 404, '', null, null],
	['Captured values are URL-decoded', 'GET', '/variables/Alpha/letters/a-slug/deadbeef/hello%20world', 200, 'Alpha|letters|a-slug|deadbeef|hello world', ROUTING_CONTROLLER, 'variableTypes'],
	['Plus signs in captured values are preserved', 'GET', '/variables/Alpha/letters/a-slug/deadbeef/hello+world', 200, 'Alpha|letters|a-slug|deadbeef|hello+world', ROUTING_CONTROLLER, 'variableTypes'],
	['Query string does not affect route matching', 'GET', '/static?filter=test', 200, 'static', ROUTING_CONTROLLER, 'staticRoute'],
	['Routes are case-sensitive', 'GET', '/STATIC', 404, '', null, null],
	['Trailing slash is normalized', 'GET', '/static/', 200, 'static', ROUTING_CONTROLLER, 'staticRoute'],
	['Malformed request URI returns 400', 'GET', 'http://[', 400, '', null, null],
	['Leading double slash is not normalized to a routed path', 'GET', '//static', 404, '', null, null],
	['First repeatable route attribute matches', 'GET', '/alias-one', 200, 'alias', ROUTING_CONTROLLER, 'aliases'],
	['Second repeatable route attribute matches', 'GET', '/alias-two', 200, 'alias', ROUTING_CONTROLLER, 'aliases'],
	['GET selects the correct action on a shared path', 'GET', '/method-specific', 200, 'get', ROUTING_CONTROLLER, 'methodSpecificGet'],
	['POST selects the correct action on a shared path', 'POST', '/method-specific', 200, 'post', ROUTING_CONTROLLER, 'methodSpecificPost'],
	['Explicit method takes priority over Any', 'GET', '/priority', 200, 'explicit', ROUTING_CONTROLLER, 'priorityGet'],
	['Any remains the fallback for other methods', 'PATCH', '/priority', 200, 'fallback', ROUTING_CONTROLLER, 'priorityFallback'],
	['Application-defined method is routed', 'PURGE', '/custom-method', 200, 'purged', ROUTING_CONTROLLER, 'customMethod'],
	['Class middleware runs before the controller', 'GET', '/middleware/class', 200, 'class>controller', MIDDLEWARE_CONTROLLER, 'classMiddleware'],
	['Class and method middleware run in order', 'GET', '/middleware/both', 200, 'class>method>controller', MIDDLEWARE_CONTROLLER, 'classAndMethodMiddlewares'],
	['Enum and exportable object middleware arguments survive caching', 'GET', '/middleware/arguments', 200, 'class>admin:managed>controller', MIDDLEWARE_CONTROLLER, 'middlewareArguments'],
	['Middleware and typed arguments share compact cache metadata', 'GET', '/middleware/typed/42', 200, 'class>controller:42', MIDDLEWARE_CONTROLLER, 'typedMiddleware'],
	['Middleware constructors only run for the dispatched route', 'GET', '/construction/second', 200, 'construct:class>construct:second>before:class>before:second>second', CONSTRUCTION_CONTROLLER, 'second', [], [], 0, 'MiddlewareConstruction'],
	['Variadic middleware arguments are validated without construction', 'GET', '/construction/third-alias', 200, 'construct:class>construct:third>before:class>before:third>third', CONSTRUCTION_CONTROLLER, 'third', [], [], 0, 'MiddlewareConstruction'],
	['Nullable, union and repeatable middleware arguments survive compilation', 'GET', '/construction/optional', 200, 'construct:class>construct:optional>construct:optional>before:class>optional:1,\'x\',0.0,false,0,null>optional:NULL,7,2.0,true,2,null>optional', CONSTRUCTION_CONTROLLER, 'optional', [], [], 0, 'MiddlewareConstruction'],
	['A middleware stops the request before the action runs', 'GET', '/stop/middleware', 200, 'trace>stopped>trace-after', STOP_CONTROLLER, 'stoppedByMiddleware', [], [], 0, 'StopRequest'],
	['An action stops the request once it produced its response', 'GET', '/stop/action', 200, 'trace>action>trace-after', STOP_CONTROLLER, 'stoppedByAction', [], [], 0, 'StopRequest'],
	['An action without route middlewares stops the request', 'GET', '/stop/bare', 200, 'bare', STOP_BARE_CONTROLLER, 'stoppedWithoutMiddleware', [], [], 0, 'StopRequest'],
	['A stopped request still unwinds the global middlewares', 'GET', '/stop/middleware', 200, 'trace>stopped>trace-after', STOP_CONTROLLER, 'stoppedByMiddleware', ['x-global-middleware' => 'true'], [], 1, 'StopRequest'],
	['Global middleware runs before a route', 'GET', '/static', 200, 'static', ROUTING_CONTROLLER, 'staticRoute', ['x-global-middleware' => 'true'], [], 1],
	['Global middlewares run in declaration order', 'GET', '/static', 200, 'static', ROUTING_CONTROLLER, 'staticRoute', ['x-global-order' => 'first,second'], [], 2],
	['Global middleware runs before automatic OPTIONS', 'OPTIONS', '/static', 204, '', null, null, ['x-global-middleware' => 'true'], [], 1],
	['Concrete controller classes are scanned', 'GET', '/scanner-concrete', 200, 'concrete', 'Tests\\Fixtures\\Scanner\\ConcreteController', 'concrete', [], [], 0, 'Scanner'],
	['Abstract controller classes are ignored', 'GET', '/scanner-abstract', 404, '', null, null, [], [], 0, 'Scanner'],
	['Inherited controller methods are ignored', 'GET', '/scanner-inherited', 404, '', null, null, [], [], 0, 'Scanner'],
	['Anonymous controller classes are ignored', 'GET', '/scanner-anonymous', 404, '', null, null, [], [], 0, 'Scanner'],
	['Static routes take deterministic precedence over overlapping dynamic routes', 'GET', '/ordering/fixed', 200, 'static', 'Tests\\Fixtures\\Ordering\\StaticController', 'fixed', [], [], 0, 'Ordering'],
	['Unknown path returns 404', 'GET', '/missing', 404, '', null, null],
	['Unsupported method returns 405', 'POST', '/static', 405, '', null, null, ['allow' => 'GET, HEAD, OPTIONS']],
	['Unknown method returns 501', 'BREW', '/static', 501, '', null, null, [], [], 0, 'NoAny'],
	['Removed CONNECT method returns 501', 'CONNECT', '/static', 501, '', null, null, [], [], 0, 'NoAny'],
	['Removed TRACE method returns 501', 'TRACE', '/static', 501, '', null, null, [], [], 0, 'NoAny'],
	['OPTIONS is handled automatically', 'OPTIONS', '/static', 204, '', null, null, ['allow' => 'GET, HEAD, OPTIONS', 'cache-control' => 'no-store']],
	['OPTIONS executes an Any route', 'OPTIONS', '/any', 200, 'any', ROUTING_CONTROLLER, 'anyMethod', ['cache-control' => 'no-store'], ['allow']],
	['OPTIONS asterisk lists only application-declared methods', 'OPTIONS', '*', 204, '', null, null, ['allow' => 'GET, HEAD, POST, DELETE, OPTIONS, PURGE']],
	['OPTIONS asterisk includes methods implied by GET', 'OPTIONS', '*', 204, '', null, null, ['allow' => 'GET, HEAD, OPTIONS'], [], 0, 'NoAny'],
	['HEAD executes GET fallback without returning its body', 'HEAD', '/head-fallback', 200, '', ROUTING_CONTROLLER, 'headFallback', ['x-head-fallback' => 'executed'], ['cache-control', 'pragma', 'expires']],
	['HEAD executes Any fallback without returning its body', 'HEAD', '/any', 200, '', ROUTING_CONTROLLER, 'anyMethod'],
	['HEAD suppresses output from global middleware before hooks', 'HEAD', '/static', 200, '', ROUTING_CONTROLLER, 'staticRoute', [], [], 3],
	['HEAD is rejected when GET is unavailable', 'HEAD', '/post-only', 405, '', null, null, ['allow' => 'POST, OPTIONS']]
];

$compilationTests = [
	['Duplicate routes are rejected', 'ConflictDuplicate', LogicException::class, 'Conflicting GET routes'],
	['Equivalent dynamic routes are rejected', 'ConflictDynamic', LogicException::class, 'Conflicting GET routes'],
	['Overlapping dynamic route types are rejected', 'ConflictOverlappingDynamic', LogicException::class, 'Ambiguous GET routes'],
	['Different dynamic structures that overlap are rejected', 'ConflictDifferentStructure', LogicException::class, 'Ambiguous GET routes'],
	['Nested dynamic structures that overlap are rejected', 'ConflictNestedStructure', LogicException::class, 'Ambiguous GET routes'],
	['Different structures with six constrained variables are rejected', 'ConflictManyVariables', LogicException::class, 'Ambiguous GET routes'],
	['Overlapping Any routes are rejected', 'ConflictAnyDynamic', LogicException::class, 'Ambiguous Any routes'],
	['Invalid HTTP method tokens are rejected', 'InvalidMethods', InvalidArgumentException::class, 'HTTP methods must be valid uppercase tokens'],
	['Lowercase HTTP methods are rejected', 'InvalidMethodCase', InvalidArgumentException::class, 'must be uppercase; use "GET" instead'],
	['Route paths must start with a slash', 'InvalidPath', InvalidArgumentException::class, 'must start with "/"'],
	['Route paths must not end with a slash', 'InvalidTrailingSlash', InvalidArgumentException::class, 'must not end with "/"'],
	['Multiple named classes in one controller file are rejected', 'MultipleClasses', LogicException::class, 'must declare at most one named class'],
	['Classes loaded from a different file are rejected', 'ClassmapMismatch', LogicException::class, 'instead of scanned file'],
	['Variables require a type', 'InvalidVariableMissingType', LogicException::class, 'Invalid variable declaration "{id}"'],
	['Variable names must be valid', 'InvalidVariableName', LogicException::class, 'Invalid variable declaration "{1id:uint}"'],
	['Variable names have a bounded length', 'InvalidVariableNameLength', LogicException::class, 'cannot exceed 32 characters'],
	['Variable names must be unique', 'InvalidVariableDuplicate', LogicException::class, 'cannot reference variable name "id" more than once'],
	['Variable types must be known', 'InvalidVariableType', LogicException::class, 'Unknown variable type "unknown"'],
	['Variables must be closed', 'InvalidVariableUnclosed', LogicException::class, 'Unclosed variable in route pattern'],
	['Closing braces must match variables', 'InvalidVariableClosingBrace', LogicException::class, 'Unexpected "}" in route pattern'],
	['Route variables must match controller parameters', 'InvalidVariableParameter', LogicException::class, 'Route variable "id" has no matching parameter'],
	['Required controller parameters must match route variables', 'MissingVariableParameter', LogicException::class, 'Required parameter "$id" of'],
	['Controllers with routes must be instantiable', 'NonInstantiableController', LogicException::class, 'must be instantiable'],
	['Controller constructors cannot require arguments', 'RequiredControllerConstructor', LogicException::class, 'constructor must not require arguments'],
	['Controller actions cannot be static', 'StaticControllerAction', LogicException::class, '::action() must not be static'],
	['Controller constructors cannot be actions', 'ConstructorControllerAction', LogicException::class, '::__construct() must not be a constructor or destructor'],
	['Controller destructors cannot be actions', 'DestructorControllerAction', LogicException::class, '::__destruct() must not be a constructor or destructor'],
	['Route parameters cannot be passed by reference', 'ReferenceRouteParameter', LogicException::class, 'must not be passed by reference'],
	['Object-typed route parameters are rejected', 'IncompatibleNamedRouteParameter', LogicException::class, 'must be untyped, string, mixed'],
	['Ambiguous scalar unions are rejected', 'AmbiguousUnionRouteParameter', LogicException::class, 'must be untyped, string, mixed'],
	['Route variables cannot populate variadic parameters', 'VariadicRouteParameter', LogicException::class, 'cannot be populated by a route variable'],
	['Middleware attributes require all constructor arguments', 'InvalidMiddlewareMissingArgument', ArgumentCountError::class, 'Argument #1 ($value) not passed'],
	['Middleware attribute arguments must match constructor types', 'InvalidMiddlewareArgumentType', TypeError::class, 'Argument #1 ($value) must be of type int, string given'],
	['Middleware attributes reject unknown named arguments', 'InvalidMiddlewareUnknownArgument', Error::class, 'Unknown named parameter $unknown'],
	['Middleware attributes must respect their declared targets', 'InvalidMiddlewareTarget', Error::class, 'cannot target method'],
	['Non-repeatable middleware attributes cannot be repeated', 'InvalidMiddlewareRepeated', Error::class, 'must not be repeated'],
	['Middleware attributes without a constructor reject arguments', 'InvalidMiddlewareNoConstructor', Error::class, 'does not have a constructor, cannot pass arguments'],
	['Middleware classes must be attributes', 'InvalidMiddlewareNotAttribute', Error::class, 'as attribute'],
	['Named middleware arguments cannot overwrite positional ones', 'InvalidMiddlewareNamedOverwrite', Error::class, 'overwrites previous argument'],
	['Middleware attributes that cannot be instantiated are rejected', 'InvalidMiddlewarePrivateConstructor', Error::class, 'Cannot instantiate middleware']
];

$failures = 0;
$assertions = 0;

foreach ($tests as $test) {
	[$name, $method, $uri, $expectedStatus, $expectedOutput, $expectedController, $expectedAction] = $test;
	$expectedHeaders = $test[7] ?? [];
	$unexpectedHeaders = $test[8] ?? [];
	$globalMiddlewareCount = (int) ($test[9] ?? 0);
	$fixtureDirectory = $test[10] ?? 'Controllers';
	$results = [];

	foreach ([false, true] as $useCache) {
		$mode = $useCache ? 'with cache' : 'without cache';
		$result = runRouteRequest($method, $uri, $globalMiddlewareCount, $fixtureDirectory, $useCache);
		$results[$mode] = $result;
		$errors = [];

		assertSame($expectedStatus, $result['status'], 'status', $errors);
		assertSame($expectedOutput, $result['output'], 'output', $errors);
		assertSame($expectedController, $result['controller'], 'controller', $errors);
		assertSame($expectedAction, $result['action'], 'action', $errors);
		assertSame(true, $result['routerReturned'], 'router returns control', $errors);
		assertSame(0, $result['exitCode'], 'exit code', $errors);
		foreach ($expectedHeaders as $header => $expectedValue) {
			assertSame($expectedValue, $result['headers'][$header] ?? null, "{$header} header", $errors);
		}
		foreach ($unexpectedHeaders as $header) {
			if (array_key_exists($header, $result['headers'])) {
				$errors[] = "{$header} header: expected it to be absent, got " . var_export($result['headers'][$header], true);
			}
		}

		$assertions++;
		if ($errors === []) {
			echo "PASS  {$name} ({$mode})\n";
			continue;
		}

		$failures++;
		echo "FAIL  {$name} ({$mode})\n";
		foreach ($errors as $error) echo "      {$error}\n";
		if ($result['diagnostics'] !== '') echo "      stderr: {$result['diagnostics']}\n";
	}

	$parityErrors = [];
	assertSame($results['without cache'], $results['with cache'], 'cached/uncached result', $parityErrors);
	$assertions++;
	if ($parityErrors === []) {
		echo "PASS  {$name} (cache parity)\n";
	} else {
		$failures++;
		echo "FAIL  {$name} (cache parity)\n";
		foreach ($parityErrors as $error) echo "      {$error}\n";
	}
}

foreach ($compilationTests as [$name, $fixtureDirectory, $expectedException, $expectedMessage]) {
	$assertions++;
	$result = runCompilationCheck($fixtureDirectory);
	// The message must be asserted too: most validations share LogicException, so a
	// fixture could otherwise pass on an unrelated error.
	if (
		$result['exitCode'] === 0
		&& $result['exception'] === $expectedException
		&& str_contains((string) $result['message'], $expectedMessage)
	) {
		echo "PASS  {$name}\n";
		continue;
	}

	$failures++;
	echo "FAIL  {$name}\n";
	echo '      expected ' . $expectedException . ' containing ' . var_export($expectedMessage, true) . ', got ' . var_export($result, true) . "\n";
}

echo sprintf("\n%d tests, %d failures\n", $assertions, $failures);
exit($failures === 0 ? 0 : 1);

function incrementDecimal(string $value): string
{
	for ($i = strlen($value) - 1; $i >= 0; $i--) {
		if ($value[$i] !== '9') {
			$value[$i] = (string) ((int) $value[$i] + 1);
			return $value;
		}
		$value[$i] = '0';
	}
	return '1' . $value;
}

/**
 * @return array{status: int, output: string, controller: ?string, action: ?string, headers: array<string,string>, routerReturned: bool, exitCode: int, diagnostics: string}
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
		'routerReturned' => $metadata['routerReturned'],
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
