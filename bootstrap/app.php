<?php

use App\Http\Middleware\LocalOrOwner;
use App\Services\ProviderFailure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (): ?string => null);
        $middleware->prependToPriorityList(SubstituteBindings::class, LocalOrOwner::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TransportExceptionInterface $e, Request $r) {
            return response()->json(['message' => 'Email delivery failed. Please try resending from the sign-in screen shortly.'], 503);
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) {
            if ($r->is('mcp')) {
                return response()->json(['message' => 'Authenticate to use Sendae.'], 401, ['WWW-Authenticate' => 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"']);
            }
        });
        $exceptions->render(function (ProviderFailure $e, Request $r) {
            return response()->json(['message' => $e->getMessage()], 422);
        });
        $exceptions->render(function (RequestException $e, Request $r) {
            return response()->json(['message' => $e->response->json('message') ?? 'The hosted server could not complete this request.', 'errors' => $e->response->json('errors') ?? []], $e->response->status());
        });
        $exceptions->render(function (ConnectionException $e, Request $r) {
            return response()->json(['message' => 'The server could not be reached. Your local drafts are safe.'], 503);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => true,
        );
    })->create();
