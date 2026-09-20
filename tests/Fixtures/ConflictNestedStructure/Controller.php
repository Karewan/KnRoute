<?php

declare(strict_types=1);

namespace Tests\Fixtures\ConflictNestedStructure;

use Karewan\KnRoute\Attributes\Get;

class Controller
{
	#[Get('/files/{path:path}')]
	public function file(string $path): void {}

	#[Get('/files/images/{id:uint}')]
	public function image(string $id): void {}
}
