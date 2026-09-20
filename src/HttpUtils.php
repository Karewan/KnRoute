<?php

declare(strict_types=1);

namespace Karewan\KnRoute;

use InvalidArgumentException;

class HttpUtils
{
	/** Headers commonly used by reverse proxies and CDNs to expose the client address. */
	private const array FORWARDED_IP_HEADERS = [
		'Forwarded',
		'X-Forwarded-For',
		'CF-Connecting-IP',
		'True-Client-IP',
		'Fastly-Client-IP',
		'X-Real-IP',
	];

	/** @var array<string,true> */
	private static array $trustedProxies = [];

	/**
	 * Get host
	 * @param bool $allowOptionalServerPort Some web servers adds the server port inside the host header
	 * @return string
	 */
	public static function getHost(bool $allowOptionalServerPort = false): string
	{
		$host = self::getHeader('Host');
		return !$allowOptionalServerPort ? explode(':', $host)[0] : $host;
	}

	/**
	 * Get path
	 * @return string
	 */
	public static function getPath(): string
	{
		$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
		return $path === '*' ? '*' : '/' . trim($path, '/');
	}

	/**
	 * Get method
	 * @return string
	 */
	public static function getMethod(): string
	{
		return $_SERVER['REQUEST_METHOD'];
	}

	/**
	 * Get protocol
	 * @return string
	 */
	public static function getProtocol(): string
	{
		return $_SERVER['SERVER_PROTOCOL'];
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
		return $contentLength === '' ? null : (int) $contentLength;
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
	 * Get client ip address (IPV4 or IPV6)
	 * @return string
	 */
	public static function getIp(): string
	{
		return self::normalizeIp();
	}

	/**
	 * Get server listen port
	 * @return int
	 */
	public static function getServerPort(): int
	{
		return intval($_SERVER['SERVER_PORT']);
	}

	/**
	 * Get client remote port
	 * @return int
	 */
	public static function getClientPort(): int
	{
		return intval($_SERVER['REMOTE_PORT']);
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
	 * @param int $depth
	 * @param int $flags
	 * @return mixed
	 */
	public static function getJsonBody(bool $associative = false, int $depth = 512, int $flags = JSON_BIGINT_AS_STRING): mixed
	{
		return json_decode(self::getBody(), $associative, $depth, $flags);
	}

	/**
	 * Output JSON
	 * @param mixed $data
	 * @param int $httpCode
	 * @return never
	 */
	public static function outputJson(mixed $data, int $httpCode = 200): never
	{
		header('Content-type: application/json; charset=utf-8', true, $httpCode);
		echo json_encode($data);
		die();
	}

	/**
	 * Output HTML
	 * @param string $html
	 * @param int $httpCode
	 * @return never
	 */
	public static function outputHtml(string $html, int $httpCode = 200): never
	{
		header('Content-type: text/html; charset=utf-8', true, $httpCode);
		echo $html;
		die();
	}

	/**
	 * Output text
	 * @param string $text
	 * @param int $httpCode
	 * @param string $charset
	 * @return never
	 */
	public static function outputText(string $text, int $httpCode = 200, string $charset = 'utf-8'): never
	{
		header("Content-type: text/plain; charset={$charset}", true, $httpCode);
		echo $text;
		die();
	}

	/**
	 * Output XML String
	 * @param string $xmlString
	 * @param int $httpCode
	 * @param string $charset
	 * @return never
	 */
	public static function outputXml(string $xmlString, int $httpCode = 200, string $charset = 'utf-8'): never
	{
		header("Content-type: application/xml; charset={$charset}", true, $httpCode);
		echo $xmlString;
		die();
	}

	/**
	 * Output String
	 * @param string $contentType
	 * @param string $str
	 * @param int $httpCode
	 * @param string $charset
	 * @return never
	 */
	public static function outputString(string $contentType, string $str, int $httpCode = 200, string $charset = 'utf-8'): never
	{
		header("Content-type: {$contentType}; charset={$charset}", true, $httpCode);
		echo $str;
		die();
	}

	/**
	 * HTTP Redirect
	 * @param string $path
	 * @param int $httpCode
	 * @return never
	 */
	public static function location(string $path = '/', int $httpCode = 302): never
	{
		header("Location: {$path}", true, $httpCode);
		die();
	}

	/**
	 * Die with HTTP status
	 * @param int $code
	 * @return never
	 */
	public static function dieStatus(int $code): never
	{
		http_response_code($code);
		die();
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

		foreach (self::FORWARDED_IP_HEADERS as $header) {
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

	private static function isTrustedProxy(string $address): bool
	{
		return isset(self::$trustedProxies[$address]);
	}
}
