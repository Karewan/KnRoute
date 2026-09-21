<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareUnknownArgument;

use Karewan\KnRoute\Attributes\Get;

final class InvalidMiddlewareController
{
	#[Get('/invalid-middleware'), NamedMiddleware(unknown: 1)]
	public function action(): void {}
}
