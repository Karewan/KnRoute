<?php
declare(strict_types=1);
namespace Tests\Fixtures\RequiredControllerConstructor;
use Karewan\KnRoute\Attributes\Get;
class Controller
{
	public function __construct(string $dependency) {}
	#[Get('/invalid')]
	public function action(): void {}
}
