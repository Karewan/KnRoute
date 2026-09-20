<?php

declare(strict_types=1);

namespace Tests\Fixtures\ClassmapMismatch;

use Karewan\KnRoute\Attributes\Get;

class MismatchedController
{
	#[Get('/classmap-mismatch')]
	public function action(): void
	{
	}
}
