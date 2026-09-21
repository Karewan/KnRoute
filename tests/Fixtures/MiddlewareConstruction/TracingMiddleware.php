<?php

declare(strict_types=1);

namespace Tests\Fixtures\MiddlewareConstruction;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class TracingMiddleware implements IMiddleware
{
	public function __construct(private readonly string $name = 'class', float $ratio = 1.0, string ...$tags)
	{
		echo "construct:{$this->name}>";
	}

	public function before(): void
	{
		echo "before:{$this->name}>";
	}

	public function after(): void {}
}
