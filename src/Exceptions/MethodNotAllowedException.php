<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Exceptions;

use RuntimeException;
use Throwable;

class MethodNotAllowedException extends RuntimeException
{
	/**
	 * Method not allowed
	 * @param string[] $allowedMethods
	 * @param string $message
	 * @param int $code
	 * @param null|Throwable $previous
	 * @return void
	 */
	public function __construct(private array $allowedMethods, string $message = '', int $code = 0, ?Throwable $previous = null)
	{
		$this->allowedMethods = $allowedMethods;
		parent::__construct($message, $code, $previous);
	}

	/**
	 * Gets the allowed HTTP methods.
	 * @return string[]
	 */
	public function getAllowedMethods(): array
	{
		return $this->allowedMethods;
	}
}
