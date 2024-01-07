<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Attributes;

use Attribute;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
class Delete extends Route
{
	/**
	 * DELETE route
	 * @param string $path
	 * @return void
	 */
	public function __construct(string $path)
	{
		parent::__construct(['DELETE'], $path);
	}
}
