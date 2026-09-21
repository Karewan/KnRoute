<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareNotAttribute;

use Karewan\KnRoute\IMiddleware;

// Deliberately missing the #[Attribute] declaration.
final class PlainMiddleware implements IMiddleware
{
	public function before(): void {}
	public function after(): void {}
}
