<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewarePrivateConstructor;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_METHOD)]
final class PrivateMiddleware implements IMiddleware
{
	private function __construct() {}
	public function before(): void {}
	public function after(): void {}
}
