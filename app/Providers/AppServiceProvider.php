<?php

namespace App\Providers;

use App\Contracts\HealthProbeLimiter;
use App\Contracts\TcpProbe;
use App\Models\ApiToken;
use App\Models\ClientToken;
use App\Services\FileHealthProbeLimiter;
use App\Services\SocketTcpProbe;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TcpProbe::class, SocketTcpProbe::class);
        $this->app->bind(HealthProbeLimiter::class, FileHealthProbeLimiter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Heartbeat, sysinfo and audit posts from RustDesk clients. A device
        // heartbeats every 15 s, every 3 s during a session (20 a minute), so
        // 120 leaves room. The per-address ceiling is for a whole fleet
        // behind one NAT: about 1,500 idle devices or 300 in sessions.
        RateLimiter::for('device-sync', fn (Request $request) => [
            Limit::perMinute(120)->by('device|'.$request->ip().'|'.(string) $request->input('id')),
            Limit::perMinute(6000)->by('address|'.$request->ip()),
        ]);

        // Guard for the RustDesk client API: Authorization: Bearer <token>
        // issued by POST /api/login and stored in client_tokens.
        Auth::viaRequest('client-token', function (Request $request) {
            $bearer = $request->bearerToken();
            if (! $bearer) {
                return null;
            }

            $token = ClientToken::query()->where('token', $bearer)->first();
            if (! $token || ! $token->isValid()) {
                return null;
            }

            if (! $token->user || ! $token->user->is_active) {
                return null;
            }

            $token->forceFill(['last_used_at' => now()])->saveQuietly();
            $request->attributes->set('client_token', $token);

            return $token->user;
        });

        // Guard for the admin automation REST API (routes/api.php "admin-api"
        // group): Authorization: Bearer <cdk_...> from api_tokens. Per-route
        // permission is enforced separately by the api-token-can middleware.
        Auth::viaRequest('api-token', function (Request $request) {
            $bearer = $request->bearerToken();
            if (! $bearer) {
                return null;
            }

            $token = ApiToken::findValid($bearer);
            if (! $token) {
                return null;
            }

            // The creator account must still exist, be active, AND still hold
            // "manage API tokens" (PLAN D4). Admin-API tokens carry the owner's
            // power; if the owner is demoted, their tokens die with the
            // privilege (mirrors the way a disabled user's tokens are revoked).
            // consoleAllows returns true for is_admin, so nothing changes for
            // tokens owned by a full administrator.
            if (! $token->user || ! $token->user->is_active
                || ! $token->user->consoleAllows('token', 'rw')) {
                return null;
            }

            // Usage stamping + permission enforcement live in the
            // api-token-can middleware (reliable regardless of guard caching).
            $request->attributes->set('api_token', $token);

            return $token->user;
        });
    }
}
