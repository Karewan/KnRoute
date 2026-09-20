<?php

declare(strict_types=1);

namespace Tests\Fixtures\ConflictManyVariables;

use Karewan\KnRoute\Attributes\Get;

class Controller
{
	#[Get('/catalog/{a:uint}/{b:uuid}/{c:uint}/{d:uuid}/{e:uint}/{f:uuid}')]
	public function constrained(string $a, string $b, string $c, string $d, string $e, string $f): void {}

	#[Get('/catalog/{everything:path}')]
	public function catchAll(string $everything): void {}
}
