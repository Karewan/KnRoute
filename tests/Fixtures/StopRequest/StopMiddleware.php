<?php

declare(strict_types=1);

namespace Tests\Fixtures\StopRequest;

use Attribute;
use Karewan\KnRoute\Exceptions\StopRequestException;
use Karewan\KnRoute\IMiddleware;

#[Attribute(Attribute::TARGET_METHOD)]
class StopMiddleware implements IMiddleware
{
	public function before(): void
	{
		echo 'stopped';
		throw new StopRequestException();
	}

	public function after(): void
	{
		echo '>stop-after';
	}
}
