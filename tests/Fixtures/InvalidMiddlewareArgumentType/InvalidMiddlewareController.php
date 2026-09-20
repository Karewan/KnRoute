<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareArgumentType;

use Karewan\KnRoute\Attributes\Get;
use Tests\Fixtures\InvalidMiddlewareArgumentType\TypedMiddleware;

final class InvalidMiddlewareController
{
	#[Get('/invalid-middleware'), TypedMiddleware('invalid')]
	public function action(): void {}
}
