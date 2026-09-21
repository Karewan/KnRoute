<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareNamedOverwrite;

use Karewan\KnRoute\Attributes\Get;

final class InvalidMiddlewareController
{
	#[Get('/invalid-middleware'), OverwriteMiddleware(1, value: 2)]
	public function action(): void {}
}
