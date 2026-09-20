<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareArgumentType;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_METHOD)]
final class TypedMiddleware implements IMiddleware
{
	public function __construct(private readonly int $value) {}
	public function before(): void {}
	public function after(): void {}
}
