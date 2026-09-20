<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middlewares;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_CLASS)]
class ClassMiddleware implements IMiddleware
{
	public function handle(): void
	{
		echo 'class>';
	}
}
