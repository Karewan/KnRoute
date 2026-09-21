<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareTarget;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_CLASS)]
final class ClassOnlyMiddleware implements IMiddleware
{
	public function before(): void {}
	public function after(): void {}
}
