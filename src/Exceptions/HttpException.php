<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Exceptions;

use InvalidArgumentException;
use RuntimeException;

class HttpException extends RuntimeException
{
	/** @param array<string,string> $headers */
	public function __construct(
		private readonly int $statusCode,
		private readonly ?string $detail = null,
		private readonly ?string $title = null,
		private readonly array $headers = [],
	) {
		if ($statusCode < 400 || $statusCode > 599) {
			throw new InvalidArgumentException('An HTTP error status code must be between 400 and 599.');
		}
		parent::__construct($detail ?? '');
	}

	public function getStatusCode(): int { return $this->statusCode; }
	public function getDetail(): ?string { return $this->detail; }
	public function getTitle(): ?string { return $this->title; }

	/** @return array<string,string> */
	public function getHeaders(): array { return $this->headers; }
}
