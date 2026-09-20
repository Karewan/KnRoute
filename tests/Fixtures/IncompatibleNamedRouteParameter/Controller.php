<?php
declare(strict_types=1);
namespace Tests\Fixtures\IncompatibleNamedRouteParameter;
use DateTimeImmutable;
use Karewan\KnRoute\Attributes\Get;
class Controller
{
	#[Get('/invalid/{value:segment}')]
	public function action(DateTimeImmutable $value): void {}
}
