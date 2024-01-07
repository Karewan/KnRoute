<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Attributes;

use Attribute;

#[Attribute]
class Post extends Route
{
	/**
	 * POST route
	 * @param string $path
	 * @param string[] $middlewares
	 * @return void
	 */
	public function __construct(string $path, array $middlewares = [])
	{
		parent::__construct(['POST'], $path, $middlewares);
	}
}
