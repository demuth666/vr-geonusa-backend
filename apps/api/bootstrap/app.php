<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (SymfonyResponse $response, Throwable $exception, Request $request): SymfonyResponse {
            if (! $request->is('api/*')) {
                return $response;
            }

            $status = $response->getStatusCode();
            $error = [
                'code' => match ($status) {
                    400 => 'BAD_REQUEST',
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    405 => 'METHOD_NOT_ALLOWED',
                    409 => 'CONFLICT',
                    419 => 'SESSION_EXPIRED',
                    422 => 'VALIDATION_ERROR',
                    429 => 'TOO_MANY_REQUESTS',
                    default => $status >= 500 ? 'SERVER_ERROR' : 'HTTP_ERROR',
                },
                'message' => match ($status) {
                    401 => 'Unauthenticated.',
                    403 => 'Forbidden.',
                    404 => 'Resource not found.',
                    422 => 'The given data was invalid.',
                    default => $status >= 500
                        ? 'Server error.'
                        : (SymfonyResponse::$statusTexts[$status] ?? 'Request failed.'),
                },
            ];

            if ($exception instanceof ValidationException) {
                $error['details'] = ['fields' => $exception->errors()];
            }

            $headers = array_diff_key($response->headers->all(), [
                'content-type' => true,
                'content-length' => true,
            ]);

            return response()->json(['error' => $error], $status, $headers);
        });
    })->create();
