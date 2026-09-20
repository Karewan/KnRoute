<?php
declare(strict_types=1);
namespace Tests\Fixtures\VariadicRouteParameter;
use Karewan\KnRoute\Attributes\Get;
class Controller
{
	#[Get('/invalid/{values:segment}')]
	public function action(string ...$values): void {}
}
