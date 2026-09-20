<?php
declare(strict_types=1);
namespace Tests\Fixtures\InvalidVariableType;
use Karewan\KnRoute\Attributes\Get;
class Controller { #[Get('/items/{id:unknown}')] public function route(): void {} }
