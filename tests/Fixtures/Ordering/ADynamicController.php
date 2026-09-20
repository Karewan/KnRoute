<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ordering;

use Karewan\KnRoute\Attributes\Get;

class ADynamicController
{
	#[Get('/ordering/{value:any}')]
	public function dynamic(string $value): void
	{
		echo "dynamic:{$value}";
	}
}
