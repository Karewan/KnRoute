<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Attributes;

use Attribute;

#[Attribute]
class Any extends Route
{
	/**
	 * ANY route => All methods are allowed
	 * @param string $path
	 * @param string[] $middlewares
	 * @return void
	 */
	public function __construct(string $path, array $middlewares = [])
	{
		parent::__construct([], $path, $middlewares);
	}
}
