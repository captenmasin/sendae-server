<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\User;
use App\Services\WorkspaceOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ConnectionController extends Controller
{
    public function ticket(Request $r)
    {
        $provider = $r->validate(['provider' => ['required', 'string', Rule::in(array_keys(config('sendae.providers')))]])['provider'];
        $this->provider($provider);
        $ticket = Str::random(64);
        Cache::put('social_connect:'.$ticket, [
            'user_id' => $r->user()->id,
            'workspace_id' => app(WorkspaceOwner::class)->workspaceId(),
            'provider' => $provider,
        ], now()->addMinutes(10));

        return ['url' => url('/connections/'.$ticket)];
    }

    public function claim(Request $r, string $ticket)
    {
        $payload = Cache::pull('social_connect:'.$ticket);
        abort_unless(is_array($payload) && isset($payload['user_id'], $payload['workspace_id'], $payload['provider']), 403, 'This connection link expired. Start again from Sendae.');
        abort_unless(array_key_exists($payload['provider'], config('sendae.providers')), 403, 'This connection link expired. Start again from Sendae.');
        $current = $r->user('web') ?? $r->user();
        abort_if($current && $current->id !== $payload['user_id'], 403, 'This connection link belongs to another account.');
        $user = User::findOrFail($payload['user_id']);
        abort_unless($user->hasVerifiedEmail(), 403);
        Auth::shouldUse('web');
        Auth::guard('web')->login($user);
        $r->session()->regenerate();
        $r->setUserResolver(fn () => Auth::guard('web')->user());

        return app(WorkspaceOwner::class)->run($user->id, fn () => $this->start($r, $payload['provider']), $payload['workspace_id']);
    }

    public function start(Request $r, string $provider)
    {
        abort_unless(config('sendae.mode') === 'server', 422, 'Connect accounts from your hosted server.');
        $config = $this->provider($provider);
        $state = Str::random(48);
        $verifier = Str::random(64);
        $r->session()->put('social_oauth', ['workspace_id' => app(WorkspaceOwner::class)->workspaceId(), 'user_id' => $r->user()->id, 'state' => $state, 'verifier' => $verifier, 'provider' => $provider, 'started' => time()]);
        [$url,$scope] = match ($provider) {
            'x' => ['https://x.com/i/oauth2/authorize', 'tweet.read tweet.write users.read offline.access media.write'],
            'threads' => ['https://threads.net/oauth/authorize', 'threads_basic,threads_content_publish,threads_manage_insights'],
            'facebook' => ['https://www.facebook.com/'.config('sendae.meta_version').'/dialog/oauth', 'pages_show_list,pages_read_engagement,pages_manage_posts,read_insights,business_management'],
            'linkedin' => ['https://www.linkedin.com/oauth/v2/authorization', 'openid profile w_member_social'],
            'linkedin_page' => ['https://www.linkedin.com/oauth/v2/authorization', 'openid profile w_organization_social rw_organization_admin r_organization_social'],
        };
        if ($provider === 'linkedin' && config('sendae.linkedin_personal_analytics')) {
            $scope .= ' r_member_postAnalytics';
        }
        $params = ['client_id' => $config['client_id'], 'redirect_uri' => url("/oauth/$provider/callback"), 'state' => $state, 'scope' => $scope, 'response_type' => 'code'];
        if ($provider === 'x') {
            $params += ['code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
        }

        return response('', 302, ['Location' => $url.'?'.http_build_query($params), 'Cache-Control' => 'no-store']);
    }

    public function callback(Request $r, string $provider)
    {
        $oauth = $r->session()->pull('social_oauth');
        abort_unless($oauth && $oauth['provider'] === $provider && time() - $oauth['started'] < 600 && hash_equals($oauth['state'], (string) $r->query('state')), 403, 'OAuth session expired or state did not match. Try connecting again.');
        abort_unless(($oauth['user_id'] ?? $r->user()->id) === $r->user()->id, 403);
        app(WorkspaceOwner::class)->select($oauth['workspace_id'] ?? $r->user()->workspace_id);
        abort_if($r->has('error') || ! $r->query('code'), 422, 'Provider connection was cancelled or denied.');
        $config = config("sendae.providers.$provider");
        $tokenUrl = match ($provider) {
            'x' => 'https://api.x.com/2/oauth2/token','threads' => 'https://graph.threads.net/oauth/access_token','facebook' => 'https://graph.facebook.com/'.config('sendae.meta_version').'/oauth/access_token',default => 'https://www.linkedin.com/oauth/v2/accessToken'
        };
        $client = Http::asForm()->acceptJson()->timeout(30);
        if ($provider === 'x') {
            $client = $client->withBasicAuth($config['client_id'], $config['client_secret']);
        }
        $params = ['grant_type' => 'authorization_code', 'code' => $r->query('code'), 'redirect_uri' => url("/oauth/$provider/callback"), 'client_id' => $config['client_id']];
        if ($provider === 'x') {
            $params['code_verifier'] = $oauth['verifier'];
        } else {
            $params['client_secret'] = $config['client_secret'];
        }
        $response = $client->post($tokenUrl, $params);
        abort_unless($response->successful() && $response->json('access_token'), 422, 'The provider could not exchange the authorization code. Check the app permissions and callback URL.');
        $token = $response->json();
        if (in_array($provider, ['threads', 'facebook'])) {
            $exchange = Http::acceptJson()->timeout(30)->get($provider === 'threads' ? 'https://graph.threads.net/access_token' : 'https://graph.facebook.com/'.config('sendae.meta_version').'/oauth/access_token', $provider === 'threads' ? ['grant_type' => 'th_exchange_token', 'client_secret' => $config['client_secret'], 'access_token' => $token['access_token']] : ['grant_type' => 'fb_exchange_token', 'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'], 'fb_exchange_token' => $token['access_token']]);
            if ($exchange->successful() && $exchange->json('access_token')) {
                $token = $exchange->json();
            }
        }
        $credentials = ['access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'] ?? null, 'expires_at' => isset($token['expires_in']) ? now()->addSeconds($token['expires_in'])->toIso8601String() : null];
        $client = Http::withToken($credentials['access_token'])->acceptJson()->timeout(30);
        $choices = [];
        if ($provider === 'x') {
            $me = $client->get('https://api.x.com/2/users/me');
            $this->check($me);
            $choices[] = ['provider_id' => $me->json('data.id'), 'name' => $me->json('data.name'), 'credentials' => $credentials];
        } elseif ($provider === 'threads') {
            $me = $client->get('https://graph.threads.net/v1.0/me', ['fields' => 'id,username']);
            $this->check($me);
            $choices[] = ['provider_id' => $me->json('id'), 'name' => $me->json('username'), 'credentials' => $credentials];
        } elseif ($provider === 'facebook') {
            $pages = $client->get('https://graph.facebook.com/'.config('sendae.meta_version').'/me/accounts', ['fields' => 'id,name,access_token,tasks', 'limit' => 100]);
            $this->check($pages);
            foreach ($pages->json('data', []) as $page) {
                if (in_array('CREATE_CONTENT', $page['tasks'] ?? []) || in_array('MANAGE', $page['tasks'] ?? [])) {
                    $choices[] = ['provider_id' => $page['id'], 'name' => $page['name'], 'credentials' => array_replace($credentials, ['access_token' => $page['access_token']])];
                }
            }
        } elseif ($provider === 'linkedin') {
            $me = $client->get('https://api.linkedin.com/v2/userinfo');
            $this->check($me);
            $choices[] = ['provider_id' => 'urn:li:person:'.$me->json('sub'), 'name' => $me->json('name'), 'credentials' => $credentials];
        } else {
            $client = $client->withHeaders(['LinkedIn-Version' => config('sendae.linkedin_version'), 'X-Restli-Protocol-Version' => '2.0.0']);
            $roles = $client->get('https://api.linkedin.com/rest/organizationAcls', ['q' => 'roleAssignee', 'state' => 'APPROVED', 'count' => 100]);
            $this->check($roles);
            foreach ($roles->json('elements', []) as $role) {
                if (! in_array($role['role'] ?? '', ['ADMINISTRATOR', 'CONTENT_ADMIN'])) {
                    continue;
                }
                $urn = $role['organization'];
                $id = Str::afterLast($urn, ':');
                $org = $client->get('https://api.linkedin.com/rest/organizations/'.$id);
                $this->check($org);
                $choices[] = ['provider_id' => $urn, 'name' => $org->json('localizedName') ?? "LinkedIn organization $id", 'credentials' => $credentials];
            }
        }
        abort_unless(count($choices), 422, 'No eligible accounts were returned. Check provider permissions and your account role.');
        $ticket = Str::random(64);
        Cache::put('social_choices:'.$ticket, ['workspace_id' => app(WorkspaceOwner::class)->workspaceId(), 'user_id' => $r->user()->id, 'provider' => $provider, 'accounts' => $choices], now()->addMinutes(10));
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response('', 302, ['Location' => 'sendae://connection?ticket='.$ticket, 'Cache-Control' => 'no-store']);
    }

    public function choices(Request $request, string $ticket): array
    {
        $selection = $this->selection($request, $ticket);

        return ['provider' => $selection['provider'], 'workspace_id' => $selection['workspace_id'], 'accounts' => array_map(fn (array $account): array => ['name' => $account['name'], 'provider_id' => $account['provider_id']], $selection['accounts'])];
    }

    public function select(Request $request, string $ticket): array
    {
        $data = $request->validate(['accounts' => 'required|array|min:1', 'accounts.*' => 'integer|min:0|distinct', 'timezone' => 'required|timezone']);

        return Cache::lock('social_selection:'.$ticket, 10)->block(2, function () use ($request, $ticket, $data): array {
            $selection = $this->selection($request, $ticket);
            foreach ($data['accounts'] as $index) {
                abort_unless(isset($selection['accounts'][$index]), 422, 'Choose an account returned by the provider.');
            }
            app(WorkspaceOwner::class)->run($request->user()->id, function () use ($selection, $data): void {
                foreach ($data['accounts'] as $index) {
                    $account = $selection['accounts'][$index];
                    Account::updateOrCreate(['provider' => $selection['provider'], 'provider_id' => $account['provider_id']], ['name' => $account['name'], 'credentials' => $account['credentials'], 'status' => 'connected', 'timezone' => $data['timezone']]);
                }
            }, $selection['workspace_id']);
            Cache::forget('social_choices:'.$ticket);

            return ['connected' => true, 'workspace_id' => $selection['workspace_id']];
        });
    }

    private function selection(Request $request, string $ticket): array
    {
        $selection = Cache::get('social_choices:'.$ticket);
        abort_unless($selection && $selection['user_id'] === $request->user()->id, 404, 'This connection expired or belongs to another account.');

        return $selection;
    }

    private function provider(string $provider): array
    {
        $config = config("sendae.providers.$provider");
        abort_unless($config && $config['client_id'] && $config['client_secret'], 422, 'Add this provider’s developer app credentials to the hosted server first. See the deployment guide.');
        abort_if(($config['approved'] ?? true) === false, 422, 'LinkedIn Company Page access is awaiting approval.');

        return $config;
    }

    private function check($response): void
    {
        abort_unless($response->successful(), 422, 'The provider could not list your accounts. Check the granted permissions and account roles.');
    }
}
