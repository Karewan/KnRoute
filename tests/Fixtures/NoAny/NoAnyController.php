<?php

declare(strict_types=1);

namespace Tests\Fixtures\NoAny;

use Karewan\KnRoute\Attributes\Get;

class NoAnyController
{
	#[Get('/static')]
	public function staticRoute(): void
	{
		echo 'static';
	}
}
