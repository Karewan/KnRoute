<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidTrailingSlash;

use Karewan\KnRoute\Attributes\Get;

class InvalidTrailingSlashController
{
	#[Get('/invalid/')]
	public function invalid(): void {}
}
