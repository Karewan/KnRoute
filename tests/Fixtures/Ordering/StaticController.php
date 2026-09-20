<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ordering;

use Karewan\KnRoute\Attributes\Get;

class StaticController
{
	#[Get('/ordering/fixed')]
	public function fixed(): void
	{
		echo 'static';
	}
}
