<?php

declare(strict_types=1);

namespace Tests {
	class HeaderCapture
	{
		/** @var string[] */
		public static array $headers = [];
	}
}

namespace Karewan\KnRoute {
	function header(string $header, bool $replace = true, int $responseCode = 0): void
	{
		\Tests\HeaderCapture::$headers[] = $header;
	}
}

namespace {
	use Karewan\KnRoute\HttpUtils;
	use Tests\HeaderCapture;

	require __DIR__ . '/../src/HttpUtils.php';

	$_SERVER = [
		'HTTP_HOST' => 'first.example:8080',
		'REQUEST_URI' => '/first/',
		'HTTP_X_CUSTOM_HEADER' => 'first',
		'REMOTE_ADDR' => '192.0.2.10',
	];

	assertSame('first.example', HttpUtils::getHost(), 'initial host');
	assertSame('/first', HttpUtils::getPath(), 'initial path');
	assertSame('first', HttpUtils::getHeader('x-CUSTOM-header'), 'case-insensitive header lookup');

	$_SERVER['HTTP_HOST'] = 'second.example';
	$_SERVER['REQUEST_URI'] = '/second/';
	$_SERVER['HTTP_X_CUSTOM_HEADER'] = 'second';
	$_SERVER['REMOTE_ADDR'] = '192.0.2.11';

	assertSame('second.example', HttpUtils::getHost(), 'uncached host');
	assertSame('/second', HttpUtils::getPath(), 'uncached path');
	assertSame('second', HttpUtils::getHeader('X-Custom-Header'), 'uncached header');
	assertSame('second', HttpUtils::getHeaders()['X-Custom-Header'] ?? null, 'normalized headers');
	assertSame('192.0.2.11', HttpUtils::getIp(), 'uncached remote address');

	HttpUtils::setTrustedProxyHeaders(['x-forwarded-for']);
	HttpUtils::setTrustedProxies(['192.0.2.11']);
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 192.0.2.11';
	assertSame('203.0.113.7', HttpUtils::getIp(), 'trusted proxy header');

	$_SERVER['REMOTE_ADDR'] = '192.0.2.12';
	assertSame('192.0.2.12', HttpUtils::getIp(), 'untrusted proxy ignores forwarding header');

	HttpUtils::setHeader('x-powered-by', 'KnRoute');
	HttpUtils::setHeaders(['content-type' => 'application/json']);
	assertSame(['X-Powered-By: KnRoute', 'Content-Type: application/json'], HeaderCapture::$headers, 'normalized response headers');

	try {
		HttpUtils::setTrustedProxies(['not-an-ip']);
		throw new RuntimeException('Invalid trusted proxy was accepted.');
	} catch (\InvalidArgumentException) {
	}

	echo "PASS  HttpUtils is stateless and normalizes headers and trusted proxies\n";

	function assertSame(mixed $expected, mixed $actual, string $label): void
	{
		if ($actual !== $expected) {
			throw new RuntimeException(sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true)));
		}
	}
}
