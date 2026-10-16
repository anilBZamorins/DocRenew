<?php

namespace App\Providers;

use App\Services\ClamAvVirusScanner;
use App\Services\HeuristicVirusScanner;
use App\Services\VirusScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Process\ExecutableFinder;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // T21 — bind the real ClamAV scanner when the host actually has
        // it installed (clamdscan preferred over clamscan: it talks to
        // the running clamd daemon instead of reloading the signature DB
        // on every call); otherwise fall back to the heuristic scanner
        // so uploads are still checked, just not against real malware
        // signatures. Production hosts are expected to have ClamAV.
        $this->app->singleton(VirusScanner::class, function () {
            $finder = new ExecutableFinder();
            $binary = $finder->find('clamdscan') ?? $finder->find('clamscan');

            return $binary ? new ClamAvVirusScanner($binary) : new HeuristicVirusScanner();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Required by bootstrap/app.php's $middleware->throttleApi().
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
