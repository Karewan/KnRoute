<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareNoConstructor;

use Karewan\KnRoute\Attributes\Get;

final class InvalidMiddlewareController
{
	#[Get('/invalid-middleware'), StatelessMiddleware('unexpected')]
	public function action(): void {}
}
