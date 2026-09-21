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

	// Nullable, union, int-to-float widening and repeated attributes must all be
	// accepted without constructing the middleware while routes are compiled.
	#[
		Get('/construction/optional'),
		OptionalMiddleware(),
		OptionalMiddleware(nullable: null, union: 7, float: 2, flag: true, list: ['a', 'b'])
	]
	public function optional(): void
	{
		echo 'optional';
	}
}
