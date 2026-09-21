<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareNotAttribute;

use Karewan\KnRoute\Attributes\Get;

final class InvalidMiddlewareController
{
	#[Get('/invalid-middleware'), PlainMiddleware]
	public function action(): void {}
}
