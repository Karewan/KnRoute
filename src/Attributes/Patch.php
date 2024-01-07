<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Attributes;

use Attribute;

#[Attribute]
class Patch extends Route
{
	/**
	 * PATCH route
	 * @param string $path
	 * @param string[] $middlewares
	 * @return void
	 */
	public function __construct(string $path, array $middlewares = [])
	{
		parent::__construct(['PATCH'], $path, $middlewares);
	}
}
