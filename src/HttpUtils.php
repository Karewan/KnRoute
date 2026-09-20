<?php

declare(strict_types=1);

namespace Karewan\KnRoute;

use InvalidArgumentException;
use Karewan\KnRoute\Exceptions\InvalidRequestUriException;

class HttpUtils
{
	/** @var string[] */
	private static array $trustedProxyHeaders = [];

	/** @var array<string,true> */
	private static array $trustedProxies = [];

	/**
	 * Get host
	 * @return string
	 */
	public static function getHost(): string
	{
		$host = self::getHeader('Host');
		if (str_starts_with($host, '[')) {
			$closingBracket = strpos($host, ']');
			return $closingBracket === false ? $host : substr($host, 0, $closingBracket + 1);
		}

		if (substr_count($host, ':') !== 1) return $host;
		return preg_replace('/:\d+$/D', '', $host) ?? $host;
	}

	/**
	 * Get path
	 * @return string
	 */
	public static function getPath(): string
	{
		if (!array_key_exists('REQUEST_URI', $_SERVER) || $_SERVER['REQUEST_URI'] === '') {
			return '/';
		}

		$requestUri = $_SERVER['REQUEST_URI'];
		if (!is_string($requestUri)) {
			throw new InvalidRequestUriException();
		}

		// Origin-form is the production hot path. Extract its path directly so
		// leading slashes are preserved without invoking the general URI parser.
		if ($requestUri[0] === '/') {
			$queryPosition = strpos($requestUri, '?');
			$path = $queryPosition === false ? $requestUri : substr($requestUri, 0, $queryPosition);
			$path = rtrim($path, '/');
			return $path === '' ? '/' : $path;
		}

		if ($requestUri === '*') return '*';

		// Absolute-form is uncommon outside proxy requests and needs full
		// validation before its path can be trusted.
		$parts = parse_url($requestUri);
		if ($parts === false || self::hasInvalidUriAuthority($parts)) {
			throw new InvalidRequestUriException();
		}

		$path = $parts['path'] ?? '';
		$path = rtrim($path, '/');
		return $path === '' ? '/' : $path;
	}

	/** @param array<string,mixed> $parts */
	private static function hasInvalidUriAuthority(array $parts): bool
	{
		$host = $parts['host'] ?? null;
		if (!is_string($host)) return false;

		return str_starts_with($host, '[') !== str_ends_with($host, ']');
	}

	/**
	 * Get method
	 * @return string
	 */
	public static function getMethod(): string
	{
		return $_SERVER['REQUEST_METHOD'] ?? '';
	}

	/**
	 * Get protocol
	 * @return string
	 */
	public static function getProtocol(): string
	{
		return $_SERVER['SERVER_PROTOCOL'] ?? '';
	}

	/**
	 * Has header
	 * @param string $name
	 * @return bool
	 */
	public static function hasHeader(string $name): bool
	{
		return self::getHeader($name) !== '';
	}

	/**
	 * Get header
	 * @param string $name
	 * @return string
	 */
	public static function getHeader(string $name): string
	{
		return self::normalizeHeaders()[self::normalizeHeaderName($name)] ?? '';
	}

	/**
	 * Get headers
	 * @return array<string,string>
	 */
	public static function getHeaders(): array
	{
		return self::normalizeHeaders();
	}

	/**
	 * Set header
	 * @param string $key
	 * @param string $value
	 * @param int $httpCode
	 * @param bool $replace
	 * @return void
	 */
	public static function setHeader(string $key, string $value, int $httpCode = 0, bool $replace = true): void
	{
		header(self::normalizeHeaderName($key) . ": {$value}", $replace, $httpCode);
	}

	/**
	 * Set headers
	 * @param array $headers
	 * @param int $httpCode
	 * @param bool $replace
	 * @return void
	 */
	public static function setHeaders(array $headers, int $httpCode = 0, bool $replace = true): void
	{
		foreach ($headers as $key => $value) self::setHeader($key, $value, $httpCode, $replace);
	}

	/**
	 * Get query string
	 * @return string
	 */
	public static function getQueryString(): string
	{
		return $_SERVER['QUERY_STRING'] ?? '';
	}

	/**
	 * Get Content-Type
	 * @return string
	 */
	public static function getContentType(): string
	{
		return self::getHeader('Content-Type');
	}

	/**
	 * Get Content-Length
	 * @return null|int
	 */
	public static function getContentLength(): ?int
	{
		$contentLength = self::getHeader('Content-Length');
		if ($contentLength === '') return null;

		$contentLength = filter_var($contentLength, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
		return $contentLength === false ? null : $contentLength;
	}

	/**
	 * Get User Agent
	 * @return string
	 */
	public static function getUserAgent(): string
	{
		return self::getHeader('User-Agent');
	}

	/**
	 * Get accept language
	 * @return string
	 */
	public static function getLanguages(): string
	{
		return self::getHeader('Accept-Language');
	}

	/**
	 * Get accept encoding
	 * @return string
	 */
	public static function getAcceptEncoding(): string
	{
		return self::getHeader('Accept-Encoding');
	}

	/**
	 * Get referer
	 * @return string
	 */
	public static function getReferer(): string
	{
		return self::getHeader('Referer');
	}

	/**
	 * Check whether the request was made with XMLHttpRequest
	 * @return bool
	 */
	public static function isXmlHttpRequest(): bool
	{
		return self::getHeader('X-Requested-With') === 'XMLHttpRequest';
	}

	/**
	 * Set the proxy IP addresses allowed to provide forwarding headers.
	 * @param string[] $proxies
	 */
	public static function setTrustedProxies(array $proxies): void
	{
		$trustedProxies = [];
		foreach ($proxies as $proxy) {
			if (!is_string($proxy) || filter_var($proxy, FILTER_VALIDATE_IP) === false) {
				throw new InvalidArgumentException(sprintf('Trusted proxy "%s" must be a valid IP address', is_scalar($proxy) ? (string) $proxy : get_debug_type($proxy)));
			}
			$trustedProxies[$proxy] = true;
		}
		self::$trustedProxies = $trustedProxies;
	}

	/**
	 * Set the forwarding headers allowed to provide the client address.
	 * @param string[] $headers
	 */
	public static function setTrustedProxyHeaders(array $headers): void
	{
		$trustedProxyHeaders = [];
		foreach ($headers as $header) {
			if (!is_string($header) || !preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $header)) {
				throw new InvalidArgumentException(sprintf('Trusted proxy header "%s" must be a valid HTTP header name', is_scalar($header) ? (string) $header : get_debug_type($header)));
			}
			$trustedProxyHeaders[self::normalizeHeaderName($header)] = true;
		}
		self::$trustedProxyHeaders = array_keys($trustedProxyHeaders);
	}

	/**
	 * Get client ip address (IPV4 or IPV6)
	 * @return string
	 */
	public static function getIp(): string
	{
		return self::normalizeIp();
	}

	/**
	 * Get server listen port
	 * @return null|int
	 */
	public static function getServerPort(): ?int
	{
		return self::normalizePort($_SERVER['SERVER_PORT'] ?? null);
	}

	/**
	 * Get client remote port
	 * @return null|int
	 */
	public static function getClientPort(): ?int
	{
		return self::normalizePort($_SERVER['REMOTE_PORT'] ?? null);
	}

	/**
	 * Get body
	 * @return string
	 */
	public static function getBody(): string
	{
		return file_get_contents('php://input') ?: '';
	}

	/**
	 * Get JSON from body
	 * @param bool $associative
	 * @param int $flags
	 * @param int $depth
	 * @return mixed
	 */
	public static function getJsonBody(bool $associative = false, int $flags = 0, int $depth = 512): mixed
	{
		return json_decode(self::getBody(), $associative, $depth, $flags);
	}

	/**
	 * Output JSON
	 * @param mixed $data
	 * @param int $httpCode
	 * @param int $flags
	 * @param int $depth
	 * @return void
	 */
	public static function outputJson(mixed $data, int $httpCode = 200, int $flags = 0, int $depth = 512): void
	{
		$json = json_encode($data, $flags | JSON_THROW_ON_ERROR, $depth);
		header('Content-type: application/json; charset=utf-8', true, $httpCode);
		echo $json;
	}

	/**
	 * Output a JSON error response.
	 *
	 * @param array<string,mixed> $extensions
	 */
	public static function outputError(
		int $code,
		?string $title = null,
		?string $detail = null,
		array $extensions = [],
		int $flags = 0,
		int $depth = 512,
	): void {
		if ($code < 400 || $code > 599) {
			throw new \InvalidArgumentException('An error status code must be between 400 and 599.');
		}

		$error = [
			'status' => $code,
			'path' => self::getPath(),
			'title' => $title ?? HttpStatus::getTitle($code),
			'detail' => $detail ?? HttpStatus::getDetail($code),
		];
		$error += $extensions;

		$json = json_encode($error, $flags | JSON_THROW_ON_ERROR, $depth);
		header('Content-type: application/json; charset=utf-8', true, $code);
		echo $json;
	}

	/**
	 * Output HTML
	 * @param string $html
	 * @param int $httpCode
	 * @return void
	 */
	public static function outputHtml(string $html, int $httpCode = 200): void
	{
		header('Content-type: text/html; charset=utf-8', true, $httpCode);
		echo $html;
	}

	/**
	 * Output text
	 * @param string $text
	 * @param int $httpCode
	 * @param string $charset
	 * @return void
	 */
	public static function outputText(string $text, int $httpCode = 200, string $charset = 'utf-8'): void
	{
		header("Content-type: text/plain; charset={$charset}", true, $httpCode);
		echo $text;
	}

	/**
	 * Output XML String
	 * @param string $xmlString
	 * @param int $httpCode
	 * @param string $charset
	 * @return void
	 */
	public static function outputXml(string $xmlString, int $httpCode = 200, string $charset = 'utf-8'): void
	{
		header("Content-type: application/xml; charset={$charset}", true, $httpCode);
		echo $xmlString;
	}

	/**
	 * Output String
	 * @param string $contentType
	 * @param string $str
	 * @param int $httpCode
	 * @param string $charset
	 * @return void
	 */
	public static function outputString(string $contentType, string $str, int $httpCode = 200, string $charset = 'utf-8'): void
	{
		header("Content-type: {$contentType}; charset={$charset}", true, $httpCode);
		echo $str;
	}

	/**
	 * HTTP Redirect
	 * @param string $path
	 * @param int $httpCode
	 * @return void
	 */
	public static function location(string $path = '/', int $httpCode = 302): void
	{
		header("Location: {$path}", true, $httpCode);
	}

	/**
	 * Set HTTP response status
	 * @param int $code
	 * @return void
	 */
	public static function setStatus(int $code): void
	{
		http_response_code($code);
	}

	/**
	 * Normalize headers
	 * @return array<string,string>
	 */
	private static function normalizeHeaders(): array
	{
		$headers = [];

		foreach ($_SERVER as $name => $value) {
			if (!is_string($name)) {
				continue;
			}

			if (strpos($name, 'REDIRECT_') === 0) {
				if (array_key_exists($name = substr($name, 9), $_SERVER)) {
					continue;
				}
			}

			if (strpos($name, 'HTTP_') === 0) {
				$headers[self::normalizeHeaderName(substr($name, 5))] = $value;
				continue;
			}

			if (strpos($name, 'CONTENT_') === 0) {
				$headers[self::normalizeHeaderName($name)] = $value;
			}
		}

		return $headers;
	}

	/**
	 * Normalize header name
	 * @param string $name
	 * @return string
	 */
	private static function normalizeHeaderName(string $name): string
	{
		return str_replace(' ', '-', ucwords(strtolower(str_replace(['_', '-'], ' ', $name))));
	}

	/**
	 * Normalize IP
	 * @return string
	 */
	private static function normalizeIp(): string
	{
		$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
		if (!self::isTrustedProxy($remoteAddress)) return $remoteAddress;

		foreach (self::$trustedProxyHeaders as $header) {
			$headerValue = self::getHeader($header);
			if ($headerValue === '') continue;

			$forwardedAddresses = $header === 'Forwarded'
				? self::parseForwardedHeader($headerValue)
				: explode(',', $headerValue);
			$currentAddress = $remoteAddress;

			for ($i = count($forwardedAddresses) - 1; $i >= 0 && self::isTrustedProxy($currentAddress); $i--) {
				$forwardedAddress = self::normalizeForwardedAddress($forwardedAddresses[$i]);
				if ($forwardedAddress === null) break;
				$currentAddress = $forwardedAddress;
			}

			return $currentAddress;
		}

		return $remoteAddress;
	}

	/** @return string[] */
	private static function parseForwardedHeader(string $header): array
	{
		preg_match_all('/(?:^|[,;])\s*for=(?:"([^"]+)"|([^,;\s]+))/i', $header, $matches, PREG_SET_ORDER);
		return array_map(static fn(array $match): string => $match[1] !== '' ? $match[1] : $match[2], $matches);
	}

	private static function normalizeForwardedAddress(string $address): ?string
	{
		$address = trim($address, " \t\n\r\0\x0B\"");
		if (preg_match('/^\[([^]]+)](?::\d+)?$/', $address, $match)) {
			$address = $match[1];
		} elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $address, $match)) {
			$address = $match[1];
		}

		return filter_var($address, FILTER_VALIDATE_IP) === false ? null : $address;
	}

	private static function normalizePort(mixed $port): ?int
	{
		$port = filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
		return $port === false ? null : $port;
	}

	private static function isTrustedProxy(string $address): bool
	{
		return isset(self::$trustedProxies[$address]);
	}
}
