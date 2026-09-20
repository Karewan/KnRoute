<?php

declare(strict_types=1);

namespace Tests\Fixtures\ConflictDuplicate;

use Karewan\KnRoute\Attributes\Get;

class DuplicateController
{
	#[Get('/duplicate')]
	public function first(): void {}

	#[Get('/duplicate')]
	public function second(): void {}
}
