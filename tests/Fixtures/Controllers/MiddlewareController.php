<?php

declare(strict_types=1);

namespace Tests\Fixtures\Controllers;

use Karewan\KnRoute\Attributes\Get;
use Tests\Fixtures\Middlewares\ClassMiddleware;
use Tests\Fixtures\Middlewares\MethodMiddleware;

#[ClassMiddleware]
class MiddlewareController
{
	#[Get('/middleware/class')]
	public function classMiddleware(): void
	{
		echo 'controller';
	}

	#[Get('/middleware/both'), MethodMiddleware]
	public function classAndMethodMiddlewares(): void
	{
		echo 'controller';
	}
}
