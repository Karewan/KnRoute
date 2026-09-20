<?php

declare(strict_types=1);

namespace Tests\Fixtures\ConflictAnyDynamic;

use Karewan\KnRoute\Attributes\Any;

class Controller
{
	#[Any('/items/{id:uint}')]
	public function byId(string $id): void {}

	#[Any('/items/{value:segment}')]
	public function byValue(string $value): void {}
}
