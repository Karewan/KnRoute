<?php
declare(strict_types=1);
namespace Tests\Fixtures\AmbiguousUnionRouteParameter;
use Karewan\KnRoute\Attributes\Get;
class Controller
{
	#[Get('/invalid/{value:segment}')]
	public function action(int|float $value): void {}
}
