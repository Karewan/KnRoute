<?php

declare(strict_types=1);

namespace Tests\Fixtures\ConflictDifferentStructure;

use Karewan\KnRoute\Attributes\Get;

class Controller
{
	#[Get('/{everything:path}')]
	public function everything(string $everything): void {}

	#[Get('/users/{id:uint}')]
	public function user(string $id): void {}
}
