<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Attributes;

use Attribute;

#[Attribute]
class Controller
{
	/**
	 * Route
	 * @param string[] $middlewares
	 * @return void
	 */
	public function __construct(
		private array $middlewares,
	) {
	}

	/**
	 * Middlewares
	 * @return string[]
	 */
	public function getMiddlewares(): array
	{
		return $this->middlewares;
	}
}
