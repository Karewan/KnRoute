<?php

declare(strict_types=1);

namespace Tests\Fixtures\MiddlewareConstruction;

use Attribute;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::IS_REPEATABLE | Attribute::TARGET_METHOD)]
final class OptionalMiddleware implements IMiddleware
{
	/** @param int[] $rest */
	public function __construct(
		private readonly ?int $nullable = 1,
		private readonly int|string $union = 'x',
		private readonly float $float = 0.0,
		private readonly bool $flag = false,
		private readonly array $list = [],
		private readonly ?TracingMiddleware $object = null
	) {
		echo 'construct:optional>';
	}

	public function before(): void
	{
		echo 'optional:'
			. var_export($this->nullable, true) . ','
			. var_export($this->union, true) . ','
			. var_export($this->float, true) . ','
			. var_export($this->flag, true) . ','
			. count($this->list) . ','
			. ($this->object === null ? 'null' : 'object')
			. '>';
	}

	public function after(): void {}
}
