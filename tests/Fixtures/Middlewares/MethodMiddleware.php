<?php

declare(strict_types=1);

namespace Tests\Fixtures\Middlewares;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_METHOD)]
class MethodMiddleware implements IMiddleware
{
	public function handle(): void
	{
		echo 'method>';
	}
}
