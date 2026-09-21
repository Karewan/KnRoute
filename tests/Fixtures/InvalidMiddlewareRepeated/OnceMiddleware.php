<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareRepeated;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_METHOD)]
final class OnceMiddleware implements IMiddleware
{
	public function before(): void {}
	public function after(): void {}
}
