<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Http\Controllers\HandlesOAuthErrors;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class AuthorizationController extends Controller
{
    use HandlesOAuthErrors;

    public function start(ServerRequestInterface $request, AuthorizationServer $server): Response
    {
        $authorization = $this->withErrorHandling(fn () => $server->validateAuthorizationRequest($request));
        $ticket = Str::random(64);
        Cache::put('authorization:'.$ticket, ['request' => serialize($authorization), 'user_id' => null], now()->addMinutes(10));

        return response('', 302, ['Location' => 'sendae://authorize?ticket='.$ticket, 'Cache-Control' => 'no-store']);
    }

    public function show(Request $request, string $ticket, ClientRepository $clients): array
    {
        return Cache::lock('authorization_lock:'.$ticket, 10)->block(2, function () use ($request, $ticket, $clients): array {
            $pending = $this->pending($request, $ticket);
            $authorization = unserialize($pending['request']);
            $client = $clients->find($authorization->getClient()->getIdentifier())?->fresh();
            abort_unless($client && ! $client->revoked, 404);
            $pending['user_id'] = $request->user()->id;
            Cache::put('authorization:'.$ticket, $pending, now()->addMinutes(5));

            return ['client' => $client->name, 'redirect_uri' => $authorization->getRedirectUri(), 'scopes' => array_map(fn ($scope): string => $scope->getIdentifier(), $authorization->getScopes())];
        });
    }

    public function decide(Request $request, string $ticket, AuthorizationServer $server, ResponseInterface $response): array
    {
        $data = $request->validate(['approved' => 'required|boolean']);

        return Cache::lock('authorization_lock:'.$ticket, 10)->block(2, function () use ($request, $ticket, $server, $response, $data): array {
            $pending = $this->pending($request, $ticket);
            abort_unless($pending['user_id'] === $request->user()->id, 403, 'Review this authorization in Sendae first.');
            $authorization = unserialize($pending['request']);
            $client = app(ClientRepository::class)->find($authorization->getClient()->getIdentifier())?->fresh();
            abort_unless($client && ! $client->revoked, 404);
            $authorization->setUser(new User($request->user()->getAuthIdentifier()));
            $authorization->setAuthorizationApproved($data['approved']);
            try {
                $result = $server->completeAuthorizationRequest($authorization, $response);
            } catch (OAuthServerException $exception) {
                $result = $exception->generateHttpResponse($response);
            }
            abort_unless($result->hasHeader('Location'), 422, 'Authorization could not be completed. Start again from the requesting client.');
            Cache::forget('authorization:'.$ticket);

            return ['redirect_url' => $result->getHeaderLine('Location')];
        });
    }

    private function pending(Request $request, string $ticket): array
    {
        $pending = Cache::get('authorization:'.$ticket);
        abort_unless($pending && ($pending['user_id'] === null || $pending['user_id'] === $request->user()->id), 404, 'This authorization expired or belongs to another account.');

        return $pending;
    }
}
