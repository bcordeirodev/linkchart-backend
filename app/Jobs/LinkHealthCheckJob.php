<?php

namespace App\Jobs;

use App\Models\Link;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class LinkHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        $http = new Client([
            'timeout' => 5,
            'connect_timeout' => 3,
            'allow_redirects' => ['max' => 5],
            'verify' => false,
            'http_errors' => false,
        ]);

        Link::where('is_active', true)
            ->select(['id', 'original_url'])
            ->chunk(50, function ($links) use ($http) {
                foreach ($links as $link) {
                    try {
                        $response = $http->head($link->original_url);
                        $code = $response->getStatusCode();
                        $status = ($code >= 200 && $code < 400) ? 'ok' : 'error';
                    } catch (\Exception $e) {
                        $status = 'error';
                    }

                    DB::table('links')
                        ->where('id', $link->id)
                        ->update([
                            'health_status' => $status,
                            'health_checked_at' => now(),
                        ]);
                }
            });
    }
}
