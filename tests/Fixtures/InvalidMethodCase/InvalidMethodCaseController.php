<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidMethodCase;

use Karewan\KnRoute\Attributes\Route;

class InvalidMethodCaseController
{
	#[Route(['get'], '/invalid')]
	public function invalid(): void {}
}
