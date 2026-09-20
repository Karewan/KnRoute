<?php
declare(strict_types=1);
namespace Tests\Fixtures\InvalidVariableUnclosed;
use Karewan\KnRoute\Attributes\Get;
class Controller { #[Get('/items/{id:uint')] public function route(): void {} }
