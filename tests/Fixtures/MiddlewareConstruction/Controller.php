<?php

declare(strict_types=1);

namespace Tests\Fixtures\MiddlewareConstruction;

use Karewan\KnRoute\Attributes\Get;
use Karewan\KnRoute\Attributes\Route;

#[TracingMiddleware]
final class Controller
{
	#[Get('/construction/first'), TracingMiddleware('first')]
	public function first(): void
	{
		echo 'first';
	}

	#[Get('/construction/second'), TracingMiddleware(name: 'second', ratio: 2)]
	public function second(): void
	{
		echo 'second';
	}

	#[Route(['POST', 'PUT'], '/construction/third'), Get('/construction/third-alias'), TracingMiddleware('third', 0.5, 'a', 'b')]
	public function third(): void
	{
		echo 'third';
	}
}
