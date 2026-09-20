<?php

declare(strict_types=1);

namespace Tests {
	class ResponseCapture
	{
		/** @var array<string,string> */
		public static array $headers = [];
	}
}

namespace Karewan\KnRoute {
	function header(string $header, bool $replace = true, int $responseCode = 0): void
	{
		[$name, $value] = array_pad(explode(':', $header, 2), 2, '');
		$name = strtolower(trim($name));
		if ($name !== '') {
			\Tests\ResponseCapture::$headers[$name] = trim($value);
		}
		\header($header, $replace, $responseCode);
	}

	function http_response_code(?int $responseCode = null): int|bool
	{
		return is_null($responseCode) ? \http_response_code() : \http_response_code($responseCode);
	}
}

namespace {
	use Karewan\KnRoute\Router;
	use Tests\ResponseCapture;

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
		'action' => $router->getFindedMethod(),
		'headers' => ResponseCapture::$headers
	];

	fwrite(STDERR, '__ROUTER_METADATA__' . json_encode($metadata, JSON_THROW_ON_ERROR));
	});

	http_response_code(200);
	$router->registerRoutesFromControllers(__DIR__ . '/Fixtures/Controllers', null);
	$router->run();
}
