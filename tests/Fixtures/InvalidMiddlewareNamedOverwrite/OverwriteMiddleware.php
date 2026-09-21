<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareNamedOverwrite;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_METHOD)]
final class OverwriteMiddleware implements IMiddleware
{
	public function __construct(private readonly int $value = 0) {}
	public function before(): void {}
	public function after(): void {}
}
