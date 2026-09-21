<?php

declare(strict_types=1);

namespace Karewan\KnRoute\Exceptions;

use RuntimeException;

/**
 * Stop the request without producing an error.
 *
 * Throw it from a middleware before hook or from a controller action once the
 * response has been produced. Raised from a before hook it cancels the controller
 * action and the remaining before hooks. Nothing is rendered: the caller owns the
 * response. The stack still unwinds, so every after hook that started runs.
 */
final class StopRequestException extends RuntimeException {}
