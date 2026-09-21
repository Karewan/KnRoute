<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewarePrivateConstructor;

use Karewan\KnRoute\Attributes\Get;

final class InvalidMiddlewareController
{
	#[Get('/invalid-middleware'), PrivateMiddleware]
	public function action(): void {}
}
