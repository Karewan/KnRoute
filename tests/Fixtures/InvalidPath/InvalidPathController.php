<?php

declare(strict_types=1);

namespace Tests\Fixtures\InvalidPath;

use Karewan\KnRoute\Attributes\Get;

class InvalidPathController
{
	#[Get('missing-leading-slash')]
	public function invalid(): void {}
}
