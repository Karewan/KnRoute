<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareTarget;

use Karewan\KnRoute\Attributes\Get;

final class InvalidMiddlewareController
{
	#[Get('/invalid-middleware'), ClassOnlyMiddleware]
	public function action(): void {}
}
