<?php

declare(strict_types=1);

namespace {
	require __DIR__ . '/../src/IMiddleware.php';
}

namespace Tests {
	use Karewan\KnRoute\IMiddleware;
	use RuntimeException;

	class MiddlewareLifecycle
	{
		/** @var string[] */
		public static array $events = [];
	}

	class LifecycleMiddleware implements IMiddleware
	{
		public function __construct(
			private readonly string $name,
			private readonly bool $crashBefore = false,
			private readonly bool $crashAfter = false,
			private readonly ?int $httpStatus = null,
			private readonly bool $recordStatus = false
		) {
		}

		public function before(): void
		{
			MiddlewareLifecycle::$events[] = "before:{$this->name}";
			if ($this->crashBefore) throw $this->crash("before:{$this->name}");
		}

		public function after(): void
		{
			MiddlewareLifecycle::$events[] = $this->recordStatus
				? "after:{$this->name}:" . http_response_code()
				: "after:{$this->name}";
			if ($this->crashAfter) throw $this->crash("after:{$this->name}");
		}

		private function crash(string $message): RuntimeException
		{
			return is_null($this->httpStatus)
				? new RuntimeException($message)
				: new \Karewan\KnRoute\Exceptions\HttpException($this->httpStatus, $message);
		}
	}

	class StoppingMiddleware implements IMiddleware
	{
		public function __construct(private readonly string $name)
		{
		}

		public function before(): void
		{
			MiddlewareLifecycle::$events[] = "before:{$this->name}";
			throw new \Karewan\KnRoute\Exceptions\StopRequestException();
		}

		public function after(): void
		{
			MiddlewareLifecycle::$events[] = "after:{$this->name}";
		}
	}

	class LifecycleController
	{
		public static bool $fail = false;
		public static bool $stop = false;

		public function action(): void
		{
			MiddlewareLifecycle::$events[] = 'action';
			if (self::$stop) throw new \Karewan\KnRoute\Exceptions\StopRequestException();
			if (self::$fail) throw new \Karewan\KnRoute\Exceptions\HttpException(409);
		}

		public function __destruct()
		{
			MiddlewareLifecycle::$events[] = 'destruct';
		}
	}
}

namespace {
	use Karewan\KnRoute\Router;
	use Tests\LifecycleMiddleware;
	use Tests\StoppingMiddleware;
	use Tests\MiddlewareLifecycle;

	const PROJECT_ROOT = __DIR__ . '/..';

	spl_autoload_register(static function (string $class): void {
		$prefixes = [
			'Karewan\\KnRoute\\' => PROJECT_ROOT . '/src/',
			'Tests\\Fixtures\\' => __DIR__ . '/Fixtures/'
		];

		foreach ($prefixes as $prefix => $directory) {
			if (!str_starts_with($class, $prefix)) continue;
			$file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($file)) require $file;
			return;
		}
	});

	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI'] = '/crash';

	assertLifecycle(false, false, ['before:A', 'before:B', 'action', 'after:B', 'after:A'], 'action crash');
	// A failing after hook does not cancel the after hooks that still have to unwind.
	assertLifecycle(false, true, ['before:A', 'before:B', 'action', 'after:B', 'after:A'], 'after:B');
	// A failing before hook cancels the action and its own after hook, not the outer ones.
	assertLifecycle(true, false, ['before:A', 'before:B', 'after:A'], 'before:B');
	// Stopping the request cancels the action without producing an error, and never
	// surfaces to the caller of run(). Completed before hooks still unwind.
	assertStopRequest(
		[new LifecycleMiddleware('global')],
		[StoppingMiddleware::class, ['local']],
		false,
		['before:global', 'before:local', 'after:global']
	);
	assertStopRequest(
		[new LifecycleMiddleware('outer'), new StoppingMiddleware('inner')],
		[LifecycleMiddleware::class, ['local']],
		false,
		['before:outer', 'before:inner', 'after:outer']
	);
	assertStopRequest(
		[new LifecycleMiddleware('global')],
		[LifecycleMiddleware::class, ['local']],
		true,
		['before:global', 'before:local', 'action', 'destruct', 'after:local', 'after:global']
	);
	// Without any middleware the dispatcher itself has to absorb the stop signal.
	assertStopRequest([], null, true, ['action', 'destruct']);

	// Whichever stack raises the HTTP error, it is rendered before the stack unwinds,
	// so every after hook sees the status the client will receive.
	assertRenderedStatusIsVisible(
		[new LifecycleMiddleware('outer', recordStatus: true), new LifecycleMiddleware('inner', true, false, 409)],
		null,
		['before:outer', 'before:inner', 'error', 'after:outer:409']
	);
	assertRenderedStatusIsVisible(
		[new LifecycleMiddleware('global', recordStatus: true)],
		['local', true, false, 409],
		['before:global', 'before:local', 'error', 'after:global:409']
	);
	assertRenderedStatusIsVisible(
		[],
		['local', false, false, null, true],
		['before:local', 'action', 'destruct', 'error', 'after:local:409'],
		true
	);

	// Partial unwinding: hooks that never completed their setup are not torn down,
	// and the ones that did unwind in reverse order.
	assertUnwinding(
		[new LifecycleMiddleware('A'), new LifecycleMiddleware('B'), new LifecycleMiddleware('C', true), new LifecycleMiddleware('D')],
		['before:A', 'before:B', 'before:C', 'after:B', 'after:A'],
		'before:C'
	);
	assertUnwinding(
		[new LifecycleMiddleware('A'), new LifecycleMiddleware('B'), new LifecycleMiddleware('C')],
		['before:A', 'before:B', 'before:C', 'action', 'after:C', 'after:B', 'after:A'],
		'action crash'
	);

	foreach ([false, true] as $global) {
		foreach ([false, true] as $local) {
			foreach ([false, true] as $fail) assertControllerLifetime($global, $local, $fail);
		}
	}

	// A route middleware that fails must not leave the controller half executed, and an
	// HttpException it raises is rendered like any other router error.
	assertRouteMiddlewareCrash(true, true, false, 409, ['before:global', 'before:local', 'error', 'after:global'], null);
	assertRouteMiddlewareCrash(false, true, false, 409, ['before:local', 'error'], null);
	assertRouteMiddlewareCrash(true, false, true, 409, ['before:global', 'before:local', 'action', 'destruct', 'after:local', 'error', 'after:global'], null);
	assertRouteMiddlewareCrash(false, false, true, 409, ['before:local', 'action', 'destruct', 'after:local', 'error'], null);

	// A non-HTTP failure is not an error response: it leaves run() once the stack has
	// unwound, so the surrounding global after hooks still run.
	assertRouteMiddlewareCrash(true, true, false, null, ['before:global', 'before:local', 'after:global'], 'before:local');
	assertRouteMiddlewareCrash(false, true, false, null, ['before:local'], 'before:local');
	assertRouteMiddlewareCrash(true, false, true, null, ['before:global', 'before:local', 'action', 'destruct', 'after:local', 'after:global'], 'after:local');

	echo "PASS  Stopped requests and failures unwind completed middlewares and observe the rendered status\n";

	function assertControllerLifetime(bool $global, bool $local, bool $fail): void
	{
		MiddlewareLifecycle::$events = [];
		\Tests\LifecycleController::$fail = $fail;
		$_SERVER['REQUEST_URI'] = '/lifetime';
		$route = new \Karewan\KnRoute\Attributes\Route(['GET'], '/lifetime');
		$route->setAction([\Tests\LifecycleController::class, 'action']);
		if ($local) $route->setExecutionMetadata([[LifecycleMiddleware::class, ['local']]], []);
		$compiled = (new \Karewan\KnRoute\Dumper\RoutesDumper([$route]))->getCompiledRoutes();
		$router = new Router();
		(new ReflectionMethod(Router::class, 'setCompiledRoutes'))->invoke($router, $compiled);
		if ($global) $router->addGlobalMiddleware(new LifecycleMiddleware('global'));
		$router->setErrorHandler(409, static function (): void {
			MiddlewareLifecycle::$events[] = 'error';
		});
		$router->run();
		$expected = $global ? ['before:global'] : [];
		if ($local) $expected[] = 'before:local';
		array_push($expected, 'action', 'destruct');
		// The error response is rendered before the stack unwinds, so every after
		// hook observes the status the client will receive.
		if ($fail) $expected[] = 'error';
		if ($local) $expected[] = 'after:local';
		if ($global) $expected[] = 'after:global';
		if (MiddlewareLifecycle::$events !== $expected) {
			throw new RuntimeException('Unexpected controller lifetime: ' . implode(', ', MiddlewareLifecycle::$events));
		}
	}

	/**
	 * @param IMiddleware[] $globalMiddlewares
	 * @param null|array{class-string,array} $localMiddleware
	 * @param string[] $expectedEvents
	 */
	function assertStopRequest(
		array $globalMiddlewares,
		?array $localMiddleware,
		bool $stopInAction,
		array $expectedEvents
	): void {
		MiddlewareLifecycle::$events = [];
		\Tests\LifecycleController::$fail = false;
		\Tests\LifecycleController::$stop = $stopInAction;
		$_SERVER['REQUEST_URI'] = '/lifetime';
		http_response_code(200);

		$route = new \Karewan\KnRoute\Attributes\Route(['GET'], '/lifetime');
		$route->setAction([\Tests\LifecycleController::class, 'action']);
		if (!is_null($localMiddleware)) $route->setExecutionMetadata([$localMiddleware], []);
		$compiled = (new \Karewan\KnRoute\Dumper\RoutesDumper([$route]))->getCompiledRoutes();

		$router = new Router();
		(new ReflectionMethod(Router::class, 'setCompiledRoutes'))->invoke($router, $compiled);
		foreach ($globalMiddlewares as $middleware) $router->addGlobalMiddleware($middleware);
		$router->setDefaultErrorHandler(static function (): void {
			MiddlewareLifecycle::$events[] = 'error';
		});

		// A stopped request is not a failure: run() must return normally.
		$router->run();
		\Tests\LifecycleController::$stop = false;

		if (http_response_code() !== 200) {
			throw new RuntimeException('A stopped request changed the response status to ' . http_response_code());
		}
		if (MiddlewareLifecycle::$events !== $expectedEvents) {
			throw new RuntimeException('Unexpected stop-request lifecycle: ' . implode(', ', MiddlewareLifecycle::$events));
		}
	}

	/**
	 * @param LifecycleMiddleware[] $globalMiddlewares
	 * @param null|array $localArguments
	 * @param string[] $expectedEvents
	 */
	function assertRenderedStatusIsVisible(
		array $globalMiddlewares,
		?array $localArguments,
		array $expectedEvents,
		bool $failAction = false
	): void {
		MiddlewareLifecycle::$events = [];
		\Tests\LifecycleController::$fail = $failAction;
		$_SERVER['REQUEST_URI'] = '/lifetime';
		http_response_code(200);

		$route = new \Karewan\KnRoute\Attributes\Route(['GET'], '/lifetime');
		$route->setAction([\Tests\LifecycleController::class, 'action']);
		if (!is_null($localArguments)) {
			$route->setExecutionMetadata([[LifecycleMiddleware::class, $localArguments]], []);
		}
		$compiled = (new \Karewan\KnRoute\Dumper\RoutesDumper([$route]))->getCompiledRoutes();

		$router = new Router();
		(new ReflectionMethod(Router::class, 'setCompiledRoutes'))->invoke($router, $compiled);
		foreach ($globalMiddlewares as $middleware) $router->addGlobalMiddleware($middleware);
		$router->setErrorHandler(409, static function (): void {
			MiddlewareLifecycle::$events[] = 'error';
		});
		$router->run();

		if (MiddlewareLifecycle::$events !== $expectedEvents) {
			throw new RuntimeException('Unexpected rendered status visibility: ' . implode(', ', MiddlewareLifecycle::$events));
		}
	}

	/**
	 * @param LifecycleMiddleware[] $middlewares
	 * @param string[] $expectedEvents
	 */
	function assertUnwinding(array $middlewares, array $expectedEvents, string $expectedException): void
	{
		MiddlewareLifecycle::$events = [];
		$_SERVER['REQUEST_URI'] = '/crash';

		$router = new Router();
		foreach ($middlewares as $middleware) $router->addGlobalMiddleware($middleware);
		$router->registerRoutesFromControllers(__DIR__ . '/Fixtures/Crash', null);

		$thrown = null;
		try {
			$router->run();
		} catch (RuntimeException $exception) {
			$thrown = $exception->getMessage();
		}

		if ($thrown !== $expectedException) {
			throw new RuntimeException("Expected exception {$expectedException}, got " . var_export($thrown, true));
		}
		if (MiddlewareLifecycle::$events !== $expectedEvents) {
			throw new RuntimeException('Unexpected unwinding: ' . implode(', ', MiddlewareLifecycle::$events));
		}
	}

	/** @param string[] $expectedEvents */
	function assertRouteMiddlewareCrash(
		bool $global,
		bool $crashBefore,
		bool $crashAfter,
		?int $httpStatus,
		array $expectedEvents,
		?string $expectedException
	): void {
		MiddlewareLifecycle::$events = [];
		\Tests\LifecycleController::$fail = false;
		$_SERVER['REQUEST_URI'] = '/lifetime';

		$route = new \Karewan\KnRoute\Attributes\Route(['GET'], '/lifetime');
		$route->setAction([\Tests\LifecycleController::class, 'action']);
		$route->setExecutionMetadata([[LifecycleMiddleware::class, ['local', $crashBefore, $crashAfter, $httpStatus]]], []);
		$compiled = (new \Karewan\KnRoute\Dumper\RoutesDumper([$route]))->getCompiledRoutes();

		$router = new Router();
		(new ReflectionMethod(Router::class, 'setCompiledRoutes'))->invoke($router, $compiled);
		if ($global) $router->addGlobalMiddleware(new LifecycleMiddleware('global'));
		$router->setErrorHandler(409, static function (): void {
			MiddlewareLifecycle::$events[] = 'error';
		});

		$thrown = null;
		try {
			$router->run();
		} catch (RuntimeException $exception) {
			$thrown = $exception->getMessage();
		}

		if ($thrown !== $expectedException) {
			throw new RuntimeException(sprintf(
				'Unexpected route middleware exception: expected %s, got %s',
				var_export($expectedException, true),
				var_export($thrown, true)
			));
		}
		if (MiddlewareLifecycle::$events !== $expectedEvents) {
			throw new RuntimeException('Unexpected route middleware lifecycle: ' . implode(', ', MiddlewareLifecycle::$events));
		}
	}

	/** @param string[] $expectedEvents */
	function assertLifecycle(bool $crashBefore, bool $crashAfter, array $expectedEvents, string $expectedException): void
	{
		MiddlewareLifecycle::$events = [];
		$router = new Router();
		$router->addGlobalMiddleware(new LifecycleMiddleware('A'));
		$router->addGlobalMiddleware(new LifecycleMiddleware('B', $crashBefore, $crashAfter));
		$router->registerRoutesFromControllers(__DIR__ . '/Fixtures/Crash', null);

		try {
			$router->run();
			throw new RuntimeException('The expected exception was not thrown.');
		} catch (RuntimeException $exception) {
			if ($exception->getMessage() !== $expectedException) {
				throw new RuntimeException("Expected exception {$expectedException}, got {$exception->getMessage()}");
			}
		}

		if (MiddlewareLifecycle::$events !== $expectedEvents) {
			throw new RuntimeException('Unexpected middleware lifecycle: ' . implode(', ', MiddlewareLifecycle::$events));
		}
	}
}
