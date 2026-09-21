<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareNoConstructor;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_METHOD)]
final class StatelessMiddleware implements IMiddleware
{
	public function before(): void {}
	public function after(): void {}
}
