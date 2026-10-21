<?php

namespace App\Providers;

use App\Models\AdmissionApplication;
use App\Policies\AdmissionApplicationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AdmissionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(AdmissionApplication::class, AdmissionApplicationPolicy::class);
        RateLimiter::for('admissions', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
