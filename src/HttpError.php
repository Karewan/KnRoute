<?php

declare(strict_types=1);

namespace Karewan\KnRoute;

final readonly class HttpError
{
	/**
	 * @param array<string,string> $headers
	 * @param array<string,mixed> $extensions custom members carried by an HttpException, empty for router errors
	 */
	public function __construct(
		public int $code,
		public string $title,
		public string $detail,
		public array $headers = [],
		public array $extensions = [],
	) {}
}
