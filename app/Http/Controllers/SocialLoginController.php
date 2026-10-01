<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Client\ConnectionException;

class SocialLoginController extends Controller
{
    public function store(Request $request, string $provider): JsonResponse
    {
        $data = $request->validate(['challenge' => 'required|string|regex:/^[A-Za-z0-9_-]{43}$/']);
        $config = config('sendae.login_providers.'.$provider);
        abort_unless($config['client_id'] && $config['client_secret'], 503, 'This sign-in provider is not available yet.');
        $ticket = Str::random(64);
        Cache::put('login_start:'.$ticket, ['provider' => $provider, 'challenge' => $data['challenge']], now()->addMinutes(10));

        return response()->json(['ticket' => $ticket, 'url' => route('social-login.start', $ticket)])->header('Cache-Control', 'no-store');
    }

    public function start(Request $request, string $ticket): Response
    {
        $login = Cache::lock('login_start_lock:'.$ticket, 10)->block(2, fn (): mixed => Cache::pull('login_start:'.$ticket));
        abort_unless($login, 410, 'Sign-in expired. Start again in Sendae.');
        $provider = $login['provider'];
        $state = Str::random(64);
        $verifier = Str::random(64);
        $request->session()->put('sendae_login.'.$state, [...$login, 'ticket' => $ticket, 'verifier' => $verifier, 'expires' => now()->addMinutes(10)->timestamp]);
        [$url, $scope] = match ($provider) {
            'google' => ['https://accounts.google.com/o/oauth2/v2/auth', 'openid email profile'],
            'facebook' => ['https://www.facebook.com/'.config('sendae.meta_version').'/dialog/oauth', 'public_profile,email'],
            'x' => ['https://x.com/i/oauth2/authorize', 'tweet.read users.read'],
        };
        $parameters = ['client_id' => config('sendae.login_providers.'.$provider.'.client_id'), 'redirect_uri' => route('social-login.callback', $provider), 'response_type' => 'code', 'scope' => $scope, 'state' => $state];
        if ($provider !== 'facebook') {
            $parameters += ['code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
        }

        return redirect()->away($url.'?'.http_build_query($parameters))->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, string $provider): Response
    {
        $data = $request->validate(['state' => 'required|string|regex:/^[A-Za-z0-9]{64}$/', 'code' => 'nullable|string|max:4096', 'error' => 'nullable|string|max:255']);
        $login = $request->session()->pull('sendae_login.'.$data['state']);
        abort_unless($login && $login['provider'] === $provider && $login['expires'] > now()->timestamp, 403, 'Sign-in expired or state did not match.');
        $result = ['challenge' => $login['challenge'], 'provider' => $provider];
        if (! empty($data['error']) || empty($data['code'])) {
            $result['error'] = 'Sign-in was cancelled. Try again.';
        } else {
            try {
                $config = config('sendae.login_providers.'.$provider);
                $parameters = ['grant_type' => 'authorization_code', 'code' => $data['code'], 'redirect_uri' => route('social-login.callback', $provider), 'client_id' => $config['client_id']];
                $client = Http::acceptJson()->asForm()->withoutRedirecting()->connectTimeout(5)->timeout(30);
                if ($provider === 'x') {
                    $client = $client->withBasicAuth($config['client_id'], $config['client_secret']);
                    $parameters['code_verifier'] = $login['verifier'];
                } else {
                    $parameters['client_secret'] = $config['client_secret'];
                    if ($provider === 'google') {
                        $parameters['code_verifier'] = $login['verifier'];
                    }
                }
                $endpoint = match ($provider) {
                    'google' => 'https://oauth2.googleapis.com/token',
                    'facebook' => 'https://graph.facebook.com/'.config('sendae.meta_version').'/oauth/access_token',
                    'x' => 'https://api.x.com/2/oauth2/token',
                };
                $token = $client->post($endpoint, $parameters)->throw()->json('access_token');
                Validator::make(['token' => $token], ['token' => 'required|string|max:10000'])->validate();
                $client = Http::acceptJson()->withToken($token)->withoutRedirecting()->connectTimeout(5)->timeout(30);
                $profile = match ($provider) {
                    'google' => $client->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json(),
                    'facebook' => $client->get('https://graph.facebook.com/'.config('sendae.meta_version').'/me', ['fields' => 'id,name,email'])->throw()->json(),
                    'x' => $client->get('https://api.x.com/2/users/me')->throw()->json('data'),
                };
                $identity = Validator::make(['provider_id' => $profile[$provider === 'google' ? 'sub' : 'id'] ?? null], ['provider_id' => 'required|string|max:255'])->validate();
                $result += $identity + ['name' => mb_substr((string) ($profile['name'] ?? ''), 0, 100), 'email' => filter_var($profile['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: ''];
            } catch (ConnectionException|RequestException|ValidationException $exception) {
                $result['error'] = 'The provider could not complete sign-in. Try again.';
            }
        }
        Cache::put('login_result:'.$login['ticket'], $result, now()->addMinutes(10));

        return response('', 302, ['Location' => 'sendae://sign-in?ticket='.$login['ticket'], 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function finish(Request $request, string $ticket): JsonResponse
    {
        $data = $request->validate(['verifier' => 'required|string|regex:/^[A-Za-z0-9]{64}$/', 'name' => 'nullable|string|max:100', 'email' => 'nullable|email|max:255', 'password' => 'nullable|string|max:1000']);

        return Cache::lock('login_finish:'.$ticket, 30)->block(2, function () use ($data, $ticket): JsonResponse {
            $result = Cache::get('login_result:'.$ticket);
            abort_unless($result, 410, 'Sign-in expired. Start again.');
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $data['verifier'], true)), '+/', '-_'), '=');
            abort_unless(hash_equals($result['challenge'], $challenge), 403, 'Start sign-in on this device first.');
            abort_if(isset($result['error']), 422, $result['error'] ?? 'Sign-in failed.');

            return Cache::lock('login_identity:'.hash('sha256', $result['provider'].':'.$result['provider_id']), 30)->block(2, function () use ($result, $data, $ticket): JsonResponse {
                $identity = DB::table('login_identities')->where('provider', $result['provider'])->where('provider_id', $result['provider_id'])->first();
                $user = $identity ? User::findOrFail($identity->user_id) : null;
                if (! $user && empty($data['email'])) {
                    return response()->json(['needs_profile' => true, 'name' => $result['name'], 'email' => $result['email']])->header('Cache-Control', 'no-store');
                }
                $session = DB::transaction(function () use ($user, $data, $result): array {
                    if (! $user) {
                        $profile = Validator::make($data, ['email' => 'required|email|max:255', 'name' => 'required|string|max:100'])->validate();
                        $profile['email'] = Str::lower(trim($profile['email']));
                        $user = User::where('email', $profile['email'])->first();
                        if ($user && ! Hash::check($data['password'] ?? '', $user->password)) {
                            throw ValidationException::withMessages(['password' => 'Enter your existing Sendae password to link this sign-in.']);
                        }
                        $user ??= User::create($profile + ['password' => Str::random(64)]);
                        DB::table('login_identities')->insert(['user_id' => $user->id, 'provider' => $result['provider'], 'provider_id' => $result['provider_id']]);
                    }

                    return ['token' => $user->createToken('Sendae desktop', ['mcp:use'])->accessToken, 'workspace_id' => $user->workspace_id, 'name' => $user->name, 'email' => $user->email];
                });
                Cache::forget('login_result:'.$ticket);

                return response()->json($session)->header('Cache-Control', 'no-store');
            });
        });
    }
}
