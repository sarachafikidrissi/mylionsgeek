<?php

namespace App\Providers;

use App\Models\Call;
use App\Models\Formation;
use App\Models\Reservation;
use App\Models\ReservationCowork;
use App\Policies\CallPolicy;
use App\Policies\FormationPolicy;
use App\Services\FaceVerification\AwsRekognitionClient;
use App\Services\FaceVerification\FacePlusPlusVerificationService;
use App\Services\FaceVerification\FaceVerificationService;
use App\Services\FaceVerification\RekognitionClient;
use App\Services\FaceVerification\UnavailableFaceVerificationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RekognitionClient::class, AwsRekognitionClient::class);

        $this->app->singleton(FaceVerificationService::class, function ($app) {
            if (! FacePlusPlusVerificationService::isConfigured()) {
                return $app->make(UnavailableFaceVerificationService::class);
            }

            return $app->make(FacePlusPlusVerificationService::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Formation::class, FormationPolicy::class);
        Gate::policy(Call::class, CallPolicy::class);

        RateLimiter::for('events-info-read', function (Request $request) {
            return Limit::perMinute(60)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('events-info-scan', function (Request $request) {
            return Limit::perMinute(120)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('events-info-book', function (Request $request) {
            return Limit::perMinute(20)->by((string) ($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('mobile-login', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(5)->by($email !== '' ? 'mobile-login-email:'.$email : 'mobile-login-ip:'.$request->ip()),
                Limit::perMinute(20)->by('mobile-login-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('mobile-forgot-password', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(3)->by($email !== '' ? 'mobile-forgot-email:'.$email : 'mobile-forgot-ip:'.$request->ip()),
                Limit::perMinute(10)->by('mobile-forgot-ip:'.$request->ip()),
            ];
        });

        Inertia::share([
            'reservationStats' => function () {
                return [
                    'reservation' => [
                        'notProcessed' => Reservation::where('approved', 0)
                            ->where('canceled', 0)
                            ->where('passed', 0)
                            ->count(),
                    ],
                    'cowork' => [
                        'notProcessed' => ReservationCowork::where('approved', 0)
                            ->where('canceled', 0)
                            ->where('passed', 0)
                            ->count(),
                    ],
                ];
            },
        ]);
    }
}
