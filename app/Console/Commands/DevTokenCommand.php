<?php

namespace App\Console\Commands;

use App\Models\Link;
use App\Models\User;
use Illuminate\Console\Command;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Dev-only helper that mints a valid JWT for local visual QA / agent login.
 *
 * Authenticated pages (the analytics dashboard, /links, etc.) require a JWT in
 * `localStorage.token`. Interactive Google/Auth0 login can't be driven headlessly,
 * so this command mints a token directly for a chosen user — letting an automated
 * browser (Playwright) inject it and reach authed pages without manual login.
 *
 * Safety: hard-aborts in the `production` environment. `artisan` is only callable
 * by someone with container/server access, so this adds no public attack surface.
 *
 * Usage:
 *   php artisan dev:token                      # owner of the link with most clicks
 *   php artisan dev:token user@example.com     # a specific user by email
 *
 * Then in the browser (or Playwright `evaluate`):
 *   localStorage.setItem('token', '<TOKEN>'); location.href = '/links/analytics/<SAMPLE_LINK_ID>';
 */
class DevTokenCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'dev:token {email? : User email (default: owner of the link with most clicks)}';

    /**
     * The console command description.
     */
    protected $description = 'Mint a JWT for local visual QA / agent login (non-production only)';

    /**
     * Resolve a user, mint a JWT, and print it alongside a ready-to-paste
     * browser snippet and a sample analytics link id.
     *
     * @return int Command exit code (0 = success, 1 = failure/guarded).
     */
    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('dev:token is disabled in production.');

            return self::FAILURE;
        }

        $email = $this->argument('email');

        // Prefer the owner of the link with the most clicks so the QA target has data.
        $busiestLink = Link::query()
            ->select('id', 'slug', 'user_id', 'clicks')
            ->orderByDesc('clicks')
            ->first();

        $user = $email
            ? User::where('email', $email)->first()
            : ($busiestLink ? User::find($busiestLink->user_id) : User::first());

        if (! $user) {
            $this->error($email ? "No user found with email {$email}." : 'No users found — seed the database first.');

            return self::FAILURE;
        }

        $token = JWTAuth::fromUser($user);

        // A link owned by this user that actually has clicks, for a meaningful QA target.
        $sampleLinkId = Link::where('user_id', $user->id)
            ->orderByDesc('clicks')
            ->value('id') ?? ($busiestLink->id ?? null);

        $this->info('✅ Dev JWT minted (non-production).');
        $this->newLine();
        $this->line('TOKEN='.$token);
        $this->line('USER='.$user->email);
        $this->line('SAMPLE_LINK_ID='.($sampleLinkId ?? '(none)'));
        $this->newLine();
        $this->comment('Browser / Playwright snippet:');
        $this->line("localStorage.setItem('token', '{$token}');");
        if ($sampleLinkId) {
            $this->line("location.href = '/links/analytics/{$sampleLinkId}';");
        }

        return self::SUCCESS;
    }
}
