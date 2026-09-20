<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMiddlewareMissingArgument;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_METHOD)]
final class RequiredMiddleware implements IMiddleware
{
	public function __construct(private readonly string $value) {}
	public function before(): void {}
	public function after(): void {}
}
