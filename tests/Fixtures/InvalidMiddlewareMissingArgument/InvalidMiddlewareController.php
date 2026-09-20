<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareMissingArgument;

use Karewan\KnRoute\Attributes\Get;
use Tests\Fixtures\InvalidMiddlewareMissingArgument\RequiredMiddleware;

final class InvalidMiddlewareController
{
	#[Get('/invalid-middleware'), RequiredMiddleware]
	public function action(): void {}
}
