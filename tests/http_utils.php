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
	use Karewan\KnRoute\Exceptions\InvalidRequestUriException;
	use Tests\HeaderCapture;

	require __DIR__ . '/../src/Exceptions/HttpException.php';
	require __DIR__ . '/../src/Exceptions/InvalidRequestUriException.php';
	require __DIR__ . '/../src/HttpUtils.php';
	require __DIR__ . '/../src/HttpStatus.php';

	$_SERVER = [
		'HTTP_HOST' => 'first.example:8080',
		'REQUEST_URI' => '/first/',
		'HTTP_X_CUSTOM_HEADER' => 'first',
		'REMOTE_ADDR' => '192.0.2.10',
	];

	assertSame('first.example', HttpUtils::getHost(), 'initial host');
	$_SERVER['HTTP_HOST'] = '[2001:db8::1]:8443';
	assertSame('[2001:db8::1]', HttpUtils::getHost(), 'IPv6 host without server port');
	$_SERVER['HTTP_HOST'] = '[2001:db8::1]';
	assertSame('[2001:db8::1]', HttpUtils::getHost(), 'IPv6 host without explicit port');
	$_SERVER['HTTP_HOST'] = 'first.example:8080';
	assertSame('/first', HttpUtils::getPath(), 'initial path');
	assertSame('first', HttpUtils::getHeader('x-CUSTOM-header'), 'case-insensitive header lookup');
	assertSame(false, HttpUtils::isXmlHttpRequest(), 'non-XHR request');
	assertSame(null, HttpUtils::getContentLength(), 'missing content length');
	$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
	assertSame(true, HttpUtils::isXmlHttpRequest(), 'XHR request');
	$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
	assertSame(false, HttpUtils::isXmlHttpRequest(), 'XHR header is case-sensitive');
	unset($_SERVER['HTTP_X_REQUESTED_WITH']);

	$_SERVER['CONTENT_LENGTH'] = '0';
	assertSame(0, HttpUtils::getContentLength(), 'zero content length');
	$_SERVER['CONTENT_LENGTH'] = '123';
	assertSame(123, HttpUtils::getContentLength(), 'content length as integer');
	$_SERVER['CONTENT_LENGTH'] = '-1';
	assertSame(null, HttpUtils::getContentLength(), 'negative content length');
	$_SERVER['CONTENT_LENGTH'] = 'invalid';
	assertSame(null, HttpUtils::getContentLength(), 'invalid content length');
	unset($_SERVER['CONTENT_LENGTH']);

	$_SERVER['HTTP_HOST'] = 'second.example';
	$_SERVER['REQUEST_URI'] = '/second/';
	$_SERVER['HTTP_X_CUSTOM_HEADER'] = 'second';
	$_SERVER['REMOTE_ADDR'] = '192.0.2.11';

	assertSame('second.example', HttpUtils::getHost(), 'uncached host');
	assertSame('/second', HttpUtils::getPath(), 'uncached path');
	assertSame('second', HttpUtils::getHeader('X-Custom-Header'), 'uncached header');
	assertSame('second', HttpUtils::getHeaders()['X-Custom-Header'] ?? null, 'normalized headers');

	// Apache exposes headers of an internally redirected request with a REDIRECT_ prefix.
	$_SERVER['REDIRECT_HTTP_X_REDIRECTED'] = 'rewritten';
	assertSame('rewritten', HttpUtils::getHeader('X-Redirected'), 'internally redirected header');
	assertSame('rewritten', HttpUtils::getHeaders()['X-Redirected'] ?? null, 'internally redirected header is listed');
	$_SERVER['HTTP_X_REDIRECTED'] = 'original';
	assertSame('original', HttpUtils::getHeader('X-Redirected'), 'the original header wins over its redirected copy');
	assertSame('original', HttpUtils::getHeaders()['X-Redirected'] ?? null, 'the original header wins when listed');
	unset($_SERVER['REDIRECT_HTTP_X_REDIRECTED'], $_SERVER['HTTP_X_REDIRECTED']);
	assertSame('', HttpUtils::getHeader('X-Redirected'), 'absent header');
	assertSame(false, HttpUtils::hasHeader('X-Redirected'), 'absent header is reported as missing');

	// Content-Length and Content-Type are CGI variables rather than HTTP_ entries.
	assertSame('', HttpUtils::getContentType(), 'missing content type');
	$_SERVER['CONTENT_TYPE'] = 'application/json; charset=utf-8';
	assertSame('application/json; charset=utf-8', HttpUtils::getContentType(), 'content type from the CGI variable');
	assertSame('application/json; charset=utf-8', HttpUtils::getHeaders()['Content-Type'] ?? null, 'content type is listed');
	unset($_SERVER['CONTENT_TYPE']);
	$_SERVER['HTTP_CONTENT_TYPE'] = 'text/plain';
	assertSame('text/plain', HttpUtils::getContentType(), 'content type from the request headers');
	unset($_SERVER['HTTP_CONTENT_TYPE']);
	assertSame('192.0.2.11', HttpUtils::getIp(), 'uncached remote address');
	assertSame(null, HttpUtils::getServerPort(), 'missing server port');
	assertSame(null, HttpUtils::getClientPort(), 'missing client port');
	$_SERVER['SERVER_PORT'] = '443';
	$_SERVER['REMOTE_PORT'] = '49152';
	assertSame(443, HttpUtils::getServerPort(), 'server port');
	assertSame(49152, HttpUtils::getClientPort(), 'client port');

	HttpUtils::setTrustedProxies(['192.0.2.11']);
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 192.0.2.11';
	assertSame('192.0.2.11', HttpUtils::getIp(), 'forwarding headers are not trusted by default');
	HttpUtils::setTrustedProxyHeaders(['x-forwarded-for']);
	assertSame('203.0.113.7', HttpUtils::getIp(), 'trusted proxy header');

	HttpUtils::setTrustedProxies(['192.0.2.12', '192.0.2.11', '2001:db8:1234::10', '2001:db8:1234::11']);
	$_SERVER['REMOTE_ADDR'] = '192.0.2.12';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 203.0.113.8, 192.0.2.11';
	assertSame('203.0.113.8', HttpUtils::getIp(), 'stop at first untrusted proxy from the right');
	$_SERVER['HTTP_X_FORWARDED_FOR'] = 'spoofed, 198.51.100.9, 192.0.2.11';
	assertSame('198.51.100.9', HttpUtils::getIp(), 'ignore spoofed values left of an untrusted address');
	$_SERVER['REMOTE_ADDR'] = '2001:db8:1234::10';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db8:ffff::20, 2001:db8:1234::11';
	assertSame('2001:db8:ffff::20', HttpUtils::getIp(), 'IPv6 proxy chain');
	unset($_SERVER['HTTP_X_FORWARDED_FOR']);
	$_SERVER['HTTP_FORWARDED'] = 'for="[2001:db8:ffff::21]:443";proto=https, for="[2001:db8:1234::11]"';
	HttpUtils::setTrustedProxyHeaders(['Forwarded']);
	assertSame('2001:db8:ffff::21', HttpUtils::getIp(), 'standard Forwarded header');
	unset($_SERVER['HTTP_FORWARDED']);
	$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.10';
	HttpUtils::setTrustedProxyHeaders(['CF-Connecting-IP']);
	assertSame('198.51.100.10', HttpUtils::getIp(), 'common CDN client IP header');

	$_SERVER['REMOTE_ADDR'] = '192.0.2.12';
	HttpUtils::setTrustedProxies(['192.0.2.11']);
	unset($_SERVER['HTTP_CF_CONNECTING_IP']);
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
	assertSame('192.0.2.12', HttpUtils::getIp(), 'untrusted proxy ignores forwarding header');

	HttpUtils::setHeader('x-powered-by', 'KnRoute');
	HttpUtils::setHeaders(['content-type' => 'application/json']);
	assertSame(['X-Powered-By: KnRoute', 'Content-Type: application/json'], HeaderCapture::$headers, 'normalized response headers');

	try {
		HttpUtils::setTrustedProxies(['not-an-ip']);
		throw new RuntimeException('Invalid trusted proxy was accepted.');
	} catch (\InvalidArgumentException) {
	}
	try {
		HttpUtils::setTrustedProxyHeaders(['Invalid Header']);
		throw new RuntimeException('Invalid trusted proxy header was accepted.');
	} catch (\InvalidArgumentException) {
	}

	$_SERVER = [];
	assertSame('/', HttpUtils::getPath(), 'missing request URI');
	$_SERVER['REQUEST_URI'] = 'http://[';
	try {
		HttpUtils::getPath();
		throw new RuntimeException('Malformed request URI was accepted.');
	} catch (InvalidRequestUriException) {
	}
	$_SERVER['REQUEST_URI'] = '//admin';
	assertSame('//admin', HttpUtils::getPath(), 'leading path slashes are preserved');
	$_SERVER['REQUEST_URI'] = 'http://example.com/absolute/form/?query=1';
	assertSame('/absolute/form', HttpUtils::getPath(), 'absolute-form request URI');
	$_SERVER['REQUEST_URI'] = 'http://example.com';
	assertSame('/', HttpUtils::getPath(), 'absolute-form request URI without a path');
	$_SERVER['REQUEST_URI'] = 'http://[2001:db8::1]:8443/absolute/ipv6';
	assertSame('/absolute/ipv6', HttpUtils::getPath(), 'absolute-form request URI with an IPv6 authority');
	$_SERVER['REQUEST_URI'] = '*';
	assertSame('*', HttpUtils::getPath(), 'asterisk-form request URI');
	assertSame('', HttpUtils::getMethod(), 'missing request method');
	assertSame('', HttpUtils::getProtocol(), 'missing server protocol');
	assertSame('', HttpUtils::getQueryString(), 'missing query string');
	assertSame(null, HttpUtils::getServerPort(), 'missing server port in empty environment');
	assertSame(null, HttpUtils::getClientPort(), 'missing client port in empty environment');
	$_SERVER['SERVER_PORT'] = '70000';
	$_SERVER['REMOTE_PORT'] = 'invalid';
	assertSame(null, HttpUtils::getServerPort(), 'out-of-range server port');
	assertSame(null, HttpUtils::getClientPort(), 'invalid client port');
	assertSame(null, HttpUtils::getJsonBody(), 'invalid JSON body returns null');

	$getJsonBody = new ReflectionMethod(HttpUtils::class, 'getJsonBody');
	assertSame(0, $getJsonBody->getParameters()[1]->getDefaultValue(), 'JSON input flags default');
	assertSame(512, $getJsonBody->getParameters()[2]->getDefaultValue(), 'JSON input depth default');

	$outputJson = new ReflectionMethod(HttpUtils::class, 'outputJson');
	assertSame(4, $outputJson->getNumberOfParameters(), 'JSON output exposes encoding parameters');
	assertSame(0, $outputJson->getParameters()[2]->getDefaultValue(), 'JSON output flags default');
	assertSame(512, $outputJson->getParameters()[3]->getDefaultValue(), 'JSON output depth default');
	assertSame('void', (string) $outputJson->getReturnType(), 'JSON output returns control');

	ob_start();
	HttpUtils::outputText('test response');
	assertSame('test response', ob_get_clean(), 'text output returns after writing response');

	HeaderCapture::$headers = [];
	$_SERVER['REQUEST_URI'] = '/missing?source=test';
	ob_start();
	HttpUtils::outputError(404, extensions: ['trace_id' => 'abc']);
	$error = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
	assertSame([
		'status' => 404,
		'path' => '/missing',
		'title' => 'Not Found',
		'detail' => 'The requested resource was not found.',
		'trace_id' => 'abc',
	], $error, 'JSON error defaults, request path and extensions');
	assertSame(['Content-type: application/json; charset=utf-8'], HeaderCapture::$headers, 'JSON error content type');

	try {
		HttpUtils::outputError(200);
		throw new RuntimeException('A successful error status was accepted.');
	} catch (\InvalidArgumentException) {
	}
	assertSame('void', (string) (new ReflectionMethod(HttpUtils::class, 'setStatus'))->getReturnType(), 'status helper returns control');

	echo "PASS  HttpUtils is stateless and normalizes headers and trusted proxies\n";

	function assertSame(mixed $expected, mixed $actual, string $label): void
	{
		if ($actual !== $expected) {
			throw new RuntimeException(sprintf('%s: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true)));
		}
	}
}
