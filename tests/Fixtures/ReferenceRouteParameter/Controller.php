<?php
declare(strict_types=1);
namespace Tests\Fixtures\ReferenceRouteParameter;
use Karewan\KnRoute\Attributes\Get;
class Controller
{
	#[Get('/invalid/{value:segment}')]
	public function action(string &$value): void {}
}
