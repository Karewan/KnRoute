<?php

declare(strict_types=1);

namespace Tests\Fixtures\ConflictOverlappingDynamic;

use Karewan\KnRoute\Attributes\Get;

class OverlappingDynamicController
{
	#[Get('/users/{id:uint}')]
	public function byId(string $id): void {}

	#[Get('/users/{value:segment}')]
	public function byValue(string $value): void {}
}
