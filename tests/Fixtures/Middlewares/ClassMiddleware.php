<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middlewares;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_CLASS)]
class ClassMiddleware implements IMiddleware
{
	public function before(): void
	{
		echo 'class>';
	}

	public function after(): void {}
}
