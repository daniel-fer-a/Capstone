<?php

namespace App\Providers;

use App\Models\AdmissionApplication;
use App\Policies\AdmissionApplicationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;

class AdmissionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(AdmissionApplication::class, AdmissionApplicationPolicy::class);
        RateLimiter::for('admissions', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('tracking', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('intakes', fn (Request $request) => [Limit::perMinute(3)->by($request->ip()), Limit::perHour(20)->by($request->ip())]);
        RateLimiter::for('sponsorships', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?? $request->ip()));
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(20)->by('ip:'.$request->ip()),
            Limit::perMinute(6)->by('identity:'.$request->ip().'|'.hash('sha256', mb_strtolower(trim(is_string($request->input('email')) ? $request->input('email') : '')))),
        ]);
        RateLimiter::for('passwords', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        ResetPassword::createUrlUsing(fn ($user, string $token) => rtrim(config('admissions.frontend_url'), '/').'/restablecer#'.http_build_query([
            'token' => $token, 'email' => $user->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986));
    }
}
