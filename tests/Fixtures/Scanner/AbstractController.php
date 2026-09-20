<?php

declare(strict_types=1);

namespace Tests\Fixtures\Scanner;

use Karewan\KnRoute\Attributes\Get;

abstract class AbstractController
{
	#[Get('/scanner-abstract')]
	public function abstractRoute(): void
	{
		echo 'abstract';
	}

	#[Get('/scanner-inherited')]
	public function inheritedRoute(): void
	{
		echo 'inherited';
	}
}
