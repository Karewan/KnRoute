<?php

declare(strict_types=1);

namespace Karewan\KnRoute;

class HttpUtils
{
	/** @var string */
	private static string $host;

	/** @var string */
	private static string $path;

	/** @var array<string,string> */
	private static array $headers;

	/** @var string */
	private static string $ip;

	/**
	 * @var string[]
	 */
	private static array $trustedProxyHeaders = [
		'CF-Connecting-IP',
		'X-Forwarded-For'
	];

	/**
	 * Get host
	 * @param bool $allowOptionalServerPort Some web servers adds the server port inside the host header
	 * @return string
	 */
	public static function getHost(bool $allowOptionalServerPort = false): string
	{
		if (!isset(self::$host)) {
			$host = self::getHeader('Host');
			self::$host = !$allowOptionalServerPort ? explode(':', $host)[0] : $host;
		}

		return self::$host;
	}

	/**
	 * Get path
	 * @return string
	 */
	public static function getPath(): string
	{
		if (!isset(self::$path)) {
			self::$path = '/' . strtolower(trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/'));
		}

		return self::$path;
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
		if (!isset(self::$headers)) {
			self::$headers = self::normalizeHeaders();
		}

		return self::$headers[$name] ?? '';
	}

	/**
	 * Get headers
	 * @return array<string,string>
	 */
	public static function getHeaders(): array
	{
		if (!isset(self::$headers)) {
			self::$headers = self::normalizeHeaders();
		}

		return self::$headers;
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
		header("{$key}: {$value}", $replace, $httpCode);
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
		foreach ($headers as $key => $value) header("{$key}: {$value}", $replace, $httpCode);
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
	 * @return string
	 */
	public static function getContentLength(): string
	{
		return self::getHeader('Content-Length');
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
	 * Settings trusted proxy headers for the getIp method
	 * @param string[] $headers
	 */
	public static function setTrustedProxyHeaders(array $headers): void
	{
		self::$trustedProxyHeaders = $headers;
	}

	/**
	 * Get client ip address (IPV4 or IPV6)
	 * @return string
	 */
	public static function getIp(): string
	{
		if (!isset(self::$ip)) {
			self::$ip = self::normalizeIp();
		}

		return self::$ip;
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
		return trim(file_get_contents('php://input') ?: '');
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
		header("Content-type: text/xml; charset={$charset}", true, $httpCode);
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
		return str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
	}

	/**
	 * Normalize IP
	 * @return string
	 */
	private static function normalizeIp(): string
	{
		foreach (self::$trustedProxyHeaders as $header) {
			$headerValue = self::getHeader($header);

			if ($headerValue !== '') {
				$ips = explode(',', $headerValue);
				$clientIp = trim($ips[0]);

				if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
					return $clientIp;
				}
			}
		}

		return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
	}
}
