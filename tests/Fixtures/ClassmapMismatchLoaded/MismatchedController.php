<?php

declare(strict_types=1);

namespace Tests\Fixtures\ClassmapMismatch;

use Karewan\KnRoute\Attributes\Get;

class MismatchedController
{
	#[Get('/wrong-classmap-target')]
	public function action(): void
	{
	}
}
