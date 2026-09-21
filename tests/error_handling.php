<?php

declare(strict_types=1);

namespace Tests {
	class ErrorResponseCapture
	{
		/** @var array<string,string> */
		public static array $headers = [];
	}
}

namespace Karewan\KnRoute {
	function header(string $header, bool $replace = true, int $responseCode = 0): void
	{
		[$name, $value] = array_pad(explode(':', $header, 2), 2, '');
		\Tests\ErrorResponseCapture::$headers[strtolower(trim($name))] = trim($value);
		\header($header, $replace, $responseCode);
	}

	function http_response_code(?int $responseCode = null): int|bool
	{
		return is_null($responseCode) ? \http_response_code() : \http_response_code($responseCode);
	}
}

namespace {
	use Karewan\KnRoute\HttpError;
	use Karewan\KnRoute\HttpUtils;
	use Karewan\KnRoute\IMiddleware;
	use Karewan\KnRoute\Router;
	use Tests\ErrorResponseCapture;

	const PROJECT_ROOT = __DIR__ . '/..';

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

	final class StatusMiddleware implements IMiddleware
	{
		public static int $statusAfter = 0;
		public function before(): void {}
		public function after(): void { self::$statusAfter = (int) http_response_code(); }
	}

	$router = new Router();
	$router->registerRoutesFromControllers(__DIR__ . '/Fixtures/Controllers', null);

	request($router, 'GET', '/missing');
	$body = responseBody($router);
	assertSame(404, http_response_code(), 'unconfigured errors keep status-only behavior');
	assertSame('', $body, 'unconfigured errors have no body');

	$router->addGlobalMiddleware(new StatusMiddleware());
	$router->setDefaultErrorHandler(static function (HttpError $error): void {
		echo "default:{$error->code}:{$error->title}:{$error->detail}";
	});
	$router->setErrorHandler(404, static function (HttpError $error): void {
		echo "specific:{$error->code}:{$error->title}:{$error->detail}";
	});

	request($router, 'GET', '/missing');
	assertSame('specific:404:Not Found:The requested resource was not found.', responseBody($router), 'specific handler overrides default handler');
	assertSame(404, StatusMiddleware::$statusAfter, 'after middleware sees rendered error status');

	request($router, 'POST', '/static');
	assertSame('default:405:Method Not Allowed:The request method is not allowed for this resource.', responseBody($router), 'default handler renders router errors');
	assertSame('GET, HEAD, OPTIONS', ErrorResponseCapture::$headers['allow'] ?? null, '405 keeps Allow header');

	$noAnyRouter = new Router();
	$noAnyRouter->registerRoutesFromControllers(__DIR__ . '/Fixtures/NoAny', null);
	$noAnyRouter->setDefaultErrorHandler(static function (HttpError $error): void {
		echo "default:{$error->code}:{$error->title}:{$error->detail}";
	});
	request($noAnyRouter, 'BREW', '/static');
	assertSame('default:501:Not Implemented:The server does not support the functionality required by the request.', responseBody($noAnyRouter), 'default handler renders unknown methods');

	request($router, 'GET', '/http-error');
	assertSame('default:422:Validation Failed:The submitted value is invalid.', responseBody($router), 'HTTP exception uses configured handler');
	assertSame('validation', ErrorResponseCapture::$headers['x-error'] ?? null, 'HTTP exception keeps custom headers');

	request($router, 'GET', '/custom-status');
	assertSame('custom response', responseBody($router), 'direct status does not invoke error handlers');
	assertSame(409, http_response_code(), 'direct status remains unchanged');

	request($router, 'HEAD', '/missing');
	assertSame('', responseBody($router), 'HEAD suppresses error handler body');
	assertSame(404, http_response_code(), 'HEAD keeps error handler status');

	try {
		$router->setErrorHandler(200, static function (): void {});
		throw new RuntimeException('A handler accepted a successful status code.');
	} catch (InvalidArgumentException) {
	}

	// An error renderer built on outputError() must survive request URIs that
	// getPath() itself rejects or that are not valid UTF-8.
	$jsonRouter = new Router();
	$jsonRouter->registerRoutesFromControllers(__DIR__ . '/Fixtures/Controllers', null);
	$jsonRouter->setDefaultErrorHandler(static function (HttpError $error): void {
		HttpUtils::outputError($error->code, $error->title, $error->detail);
	});

	request($jsonRouter, 'GET', 'http://[');
	$body = responseBody($jsonRouter);
	assertSame(400, http_response_code(), 'malformed request URI keeps its status');
	assertSame([
		'status' => 400,
		'path' => '',
		'title' => 'Bad Request',
		'detail' => 'The request URI is invalid.',
	], json_decode($body, true, flags: JSON_THROW_ON_ERROR), 'malformed request URI renders a JSON error');

	request($jsonRouter, 'GET', "/caf\xe9");
	$body = responseBody($jsonRouter);
	assertSame(404, http_response_code(), 'invalid UTF-8 path keeps its status');
	assertSame(
		'/caf' . "\u{FFFD}",
		json_decode($body, true, flags: JSON_THROW_ON_ERROR)['path'] ?? null,
		'invalid UTF-8 path is substituted instead of failing to encode'
	);

	echo "PASS  HTTP errors support status-specific and default renderers\n";

	function request(Router $router, string $method, string $uri): void
	{
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI'] = $uri;
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
		ErrorResponseCapture::$headers = [];
		http_response_code(200);
		ob_start();
	}

	function responseBody(Router $router): string
	{
		$router->run();
		return ob_get_clean();
	}

	function assertSame(mixed $expected, mixed $actual, string $label): void
	{
		if ($actual !== $expected) {
			throw new RuntimeException(sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true)));
		}
	}
}
