<?php

declare(strict_types=1);

namespace Tests\Fixtures\StopRequest;

use Karewan\KnRoute\Attributes\Get;
use Karewan\KnRoute\Exceptions\StopRequestException;

// No class middleware: the action runs without a route middleware stack, so the
// stop signal has to be handled by the dispatcher itself.
class BareController
{
	#[Get('/stop/bare')]
	public function stoppedWithoutMiddleware(): void
	{
		echo 'bare';
		throw new StopRequestException();
	}
}
