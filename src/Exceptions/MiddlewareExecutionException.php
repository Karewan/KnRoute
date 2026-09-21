<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Exceptions;

use RuntimeException;
use Throwable;

/** @internal */
final class MiddlewareExecutionException extends RuntimeException
{
	/**
	 * @param Throwable $middlewareException The exception raised by the middleware hook.
	 * @param null|Throwable $pendingException An exception the stack was already unwinding, kept as the cause.
	 */
	public function __construct(private readonly Throwable $middlewareException, ?Throwable $pendingException = null)
	{
		parent::__construct('A middleware hook failed.', 0, $pendingException ?? $middlewareException);
	}

	public function getMiddlewareException(): Throwable
	{
		return $this->middlewareException;
	}
}
