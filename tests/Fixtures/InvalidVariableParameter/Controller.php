<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidVariableParameter;

use Karewan\KnRoute\Attributes\Get;

class Controller
{
	// The route variable "id" has no matching parameter; "$item" is optional so the
	// missing-required-parameter validation cannot mask it.
	#[Get('/items/{id:uint}')]
	public function route(string $item = ''): void {}
}
