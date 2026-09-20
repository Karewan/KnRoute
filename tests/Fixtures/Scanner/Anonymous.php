<?php

declare(strict_types=1);

namespace Tests\Fixtures\Scanner;

use Karewan\KnRoute\Attributes\Get;

$anonymousController = new class {
	#[Get('/scanner-anonymous')]
	public function anonymousRoute(): void
	{
		echo 'anonymous';
	}
};
