<?php

namespace App\Jobs;

use App\Logging\AppLogger;
use App\Logging\Context\HasLogContext;
use App\Models\User;
use App\Services\EmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Sends the pt-BR welcome email to a user on their first verified access.
 *
 * Two dispatch points enqueue this job, and the job alone decides whether to send:
 *
 *   UserObserver::created ───────────────────┐
 *                                            ├──→ SendWelcomeEmailJob
 *   EmailVerificationService::verifyEmail ───┘
 *
 * - Auth0/Google users are verified by construction (`hasVerifiedEmail()` short-circuits
 *   on a filled `auth0_sub`), so the `created` dispatch sends immediately and the
 *   verification endpoint is never reached in that flow.
 * - Email/password users are unverified at `created`, so that dispatch returns without
 *   sending; the email goes out on the second dispatch, right after they verify.
 *
 * Delivery is AT MOST ONCE, not at least once. `welcome_email_sent_at` is claimed in a
 * single conditional UPDATE before the SendGrid call, so a retry (tries = 3) following a
 * successful send cannot deliver a duplicate. The trade-off is deliberate: for a welcome
 * email, dropping one is better than sending three.
 *
 * A genuine failure is recorded by `AppLogger::jobFailed` and the job is marked failed via
 * `$this->fail()` — it is deliberately NOT retried. The claim was already taken before the
 * send, so a retry could never deliver anyway; retrying would only produce noisy no-op runs.
 * That means a real SendGrid failure (bad key, quota, network) permanently loses that user's
 * welcome email — the `jobs` log entry is the only record.
 *
 * @see \App\Models\Observers\UserObserver
 * @see \App\Services\EmailVerificationService::verifyEmail()
 * @see \App\Jobs\SeedDemoLinkJob
 */
class SendWelcomeEmailJob implements ShouldQueue
{
    use Dispatchable, HasLogContext, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 60;

    public function __construct(public readonly int $userId) {}

    /**
     * Guard, claim, then send. Returns quietly when the user is gone, still unverified,
     * or when another dispatch/retry already claimed the send.
     */
    public function handle(EmailService $emailService): void
    {
        $this->pushLogContext();
        $start = microtime(true);
        AppLogger::jobStarted(static::class, ['user_id' => $this->userId]);

        try {
            $user = User::find($this->userId);

            if (! $user || ! $user->hasVerifiedEmail()) {
                AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000);

                return;
            }

            // Atomic claim: flips NULL → now() in one statement. A losing racer
            // (concurrent dispatch or retry) sees 0 affected rows and bows out.
            $claimed = User::whereKey($this->userId)
                ->whereNull('welcome_email_sent_at')
                ->update(['welcome_email_sent_at' => now()]);

            if ($claimed === 0) {
                AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000);

                return;
            }

            $data = [
                'user_name' => $user->name,
                'links_url' => rtrim(config('app.frontend_url', config('app.url')), '/').'/links',
            ];

            $result = $emailService->sendEmailViaSendGridAPI(
                $user->email,
                'Bem-vindo ao Link Charts!',
                view('emails.welcome', $data)->render(),
                view('emails.welcome-text', $data)->render(),
                $user->name,
            );

            // sendEmailViaSendGridAPI() catches its own exceptions and returns
            // ['success' => false, ...] instead of throwing, so a genuine SendGrid
            // failure must be inspected explicitly — otherwise it would fall through
            // to jobSucceeded() below despite no email having been delivered.
            if (! ($result['success'] ?? false)) {
                $e = new RuntimeException(
                    'SendGrid recusou o envio das boas-vindas: '.($result['error'] ?? 'motivo desconhecido')
                );

                AppLogger::jobFailed(static::class, $e, $this->attempts());
                $this->fail($e);

                return;
            }

            AppLogger::jobSucceeded(static::class, (microtime(true) - $start) * 1000);
        } catch (Throwable $e) {
            AppLogger::jobFailed(static::class, $e, $this->attempts());
            throw $e;
        } finally {
            $this->popLogContext();
        }
    }

    /**
     * Final failure callback (after exhausting retries).
     */
    public function failed(Throwable $e): void
    {
        AppLogger::jobFailed(static::class, $e, $this->tries);
    }

    /** {@inheritDoc} */
    protected function logContextRequestId(): ?string
    {
        return null;
    }

    /** {@inheritDoc} */
    protected function logContextUserId(): ?int
    {
        return $this->userId;
    }
}
