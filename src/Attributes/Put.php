<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Attributes;

use Attribute;

#[Attribute]
class Put extends Route
{
	/**
	 * PUT route
	 * @param string $path
	 * @param string[] $middlewares
	 * @return void
	 */
	public function __construct(string $path, array $middlewares = [])
	{
		parent::__construct(['PUT'], $path, $middlewares);
	}
}
