<?php

declare(strict_types=1);

namespace Karewan\KnRoute;

/** Usable HTTP client and server error status metadata. */
final class HttpStatus
{
	/** @var array<int,array{string,string}> */
	private const array STATUSES = [
		400 => ['Bad Request', 'The server could not understand the request.'],
		401 => ['Unauthorized', 'Authentication is required to access this resource.'],
		402 => ['Payment Required', 'Payment is required to access this resource.'],
		403 => ['Forbidden', 'Access to this resource is forbidden.'],
		404 => ['Not Found', 'The requested resource was not found.'],
		405 => ['Method Not Allowed', 'The request method is not allowed for this resource.'],
		406 => ['Not Acceptable', 'No acceptable representation is available.'],
		407 => ['Proxy Authentication Required', 'Authentication with the proxy is required.'],
		408 => ['Request Timeout', 'The server timed out waiting for the request.'],
		409 => ['Conflict', 'The request conflicts with the current state of the resource.'],
		410 => ['Gone', 'The requested resource is no longer available.'],
		411 => ['Length Required', 'The request must include a valid Content-Length header.'],
		412 => ['Precondition Failed', 'A request precondition was not met.'],
		413 => ['Content Too Large', 'The request content is larger than the server is willing to process.'],
		414 => ['URI Too Long', 'The request URI is longer than the server is willing to process.'],
		415 => ['Unsupported Media Type', 'The request media type is not supported.'],
		416 => ['Range Not Satisfiable', 'The requested range cannot be satisfied.'],
		417 => ['Expectation Failed', 'The server cannot meet the request expectations.'],
		421 => ['Misdirected Request', 'The request was directed to a server unable to produce a response.'],
		422 => ['Unprocessable Content', 'The request content could not be processed.'],
		423 => ['Locked', 'The requested resource is locked.'],
		424 => ['Failed Dependency', 'The request failed because a dependent action failed.'],
		425 => ['Too Early', 'The server is unwilling to process a request that might be replayed.'],
		426 => ['Upgrade Required', 'The client must switch to a different protocol.'],
		428 => ['Precondition Required', 'The server requires the request to be conditional.'],
		429 => ['Too Many Requests', 'Too many requests were sent in a given amount of time.'],
		431 => ['Request Header Fields Too Large', 'The request headers are too large.'],
		451 => ['Unavailable For Legal Reasons', 'The resource is unavailable for legal reasons.'],
		500 => ['Internal Server Error', 'The server encountered an unexpected condition.'],
		501 => ['Not Implemented', 'The server does not support the functionality required by the request.'],
		502 => ['Bad Gateway', 'The server received an invalid response from an upstream server.'],
		503 => ['Service Unavailable', 'The server is temporarily unable to handle the request.'],
		504 => ['Gateway Timeout', 'The server did not receive a timely response from an upstream server.'],
		505 => ['HTTP Version Not Supported', 'The request HTTP version is not supported.'],
		506 => ['Variant Also Negotiates', 'The selected representation creates a negotiation loop.'],
		507 => ['Insufficient Storage', 'The server cannot store the representation needed to complete the request.'],
		508 => ['Loop Detected', 'The server detected an infinite loop while processing the request.'],
		511 => ['Network Authentication Required', 'Authentication is required to gain network access.'],
	];

	public static function getTitle(int $code): string
	{
		return self::STATUSES[$code][0] ?? match (intdiv($code, 100)) {
			4 => 'Client Error', 5 => 'Server Error', default => 'Unknown Error',
		};
	}

	public static function getDetail(int $code): string
	{
		return self::STATUSES[$code][1] ?? match (intdiv($code, 100)) {
			4 => 'The request could not be processed.',
			5 => 'The server could not complete the request.',
			default => 'The response uses an unknown error status code.',
		};
	}
}
