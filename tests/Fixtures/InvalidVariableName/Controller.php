<?php
declare(strict_types=1);
namespace Tests\Fixtures\InvalidVariableName;
use Karewan\KnRoute\Attributes\Get;
class Controller { #[Get('/items/{1id:uint}')] public function route(): void {} }
