<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Exceptions;

use RuntimeException;
use Throwable;

/** @internal */
final class MiddlewareExecutionException extends RuntimeException
{
	public function __construct(private readonly Throwable $middlewareException)
	{
		parent::__construct('A middleware hook failed.', 0, $middlewareException);
	}

	public function getMiddlewareException(): Throwable
	{
		return $this->middlewareException;
	}
}
