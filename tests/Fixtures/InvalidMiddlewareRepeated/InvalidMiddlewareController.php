<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareRepeated;

use Karewan\KnRoute\Attributes\Get;

final class InvalidMiddlewareController
{
	#[Get('/invalid-middleware'), OnceMiddleware, OnceMiddleware]
	public function action(): void {}
}
