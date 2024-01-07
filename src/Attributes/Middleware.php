<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Attributes;

use Attribute;

#[Attribute]
class Middleware
{
	/**
	 * Route
	 * @param string $class
	 * @param array $parameters
	 * @return void
	 */
	public function __construct(
		private string $class,
		private array $parameters = []
	) {
	}

	/**
	 * Get class
	 * @return string
	 */
	public function getClass(): string
	{
		return $this->class;
	}

	/**
	 * Get parameters
	 * @return array
	 */
	public function getParameters(): array
	{
		return $this->parameters;
	}
}
