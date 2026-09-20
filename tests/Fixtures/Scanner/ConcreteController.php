<?php

declare(strict_types=1);

namespace Tests\Fixtures\Scanner;

use Karewan\KnRoute\Attributes\Get;

class ConcreteController extends AbstractController
{
	#[Get('/scanner-concrete')]
	public function concrete(): void
	{
		echo 'concrete';
	}
}
