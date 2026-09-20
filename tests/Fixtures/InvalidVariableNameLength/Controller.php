<?php
declare(strict_types=1);
namespace Tests\Fixtures\InvalidVariableNameLength;
use Karewan\KnRoute\Attributes\Get;
class Controller { #[Get('/items/{abcdefghijklmnopqrstuvwxyzabcdefg:uint}')] public function route(): void {} }
