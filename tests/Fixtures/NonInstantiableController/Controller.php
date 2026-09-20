<?php
declare(strict_types=1);
namespace Tests\Fixtures\NonInstantiableController;
use Karewan\KnRoute\Attributes\Get;
class Controller
{
	private function __construct() {}
	#[Get('/invalid')]
	public function action(): void {}
}
