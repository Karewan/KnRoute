<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMethods;

use Karewan\KnRoute\Attributes\Route;

class InvalidMethodController
{
	#[Route(['BAD METHOD'], '/invalid')]
	public function invalid(): void {}
}
