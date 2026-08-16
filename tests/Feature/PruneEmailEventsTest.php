<?php

namespace Tests\Feature;

use App\Models\EmailEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prune LGPD de email_events: eventos além da janela somem, recentes ficam,
 * e a janela é configurável por --days.
 */
class PruneEmailEventsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Atalho: evento delivered com created_at deslocado para trás.
     */
    private function eventAgedDays(int $days): EmailEvent
    {
        $event = EmailEvent::create([
            'email' => 'ana@example.com',
            'event' => 'delivered',
            'occurred_at' => now()->subDays($days),
        ]);

        // created_at manda no prune; forçar a idade sem depender de travel().
        $event->timestamps = false;
        $event->forceFill(['created_at' => now()->subDays($days)])->saveQuietly();

        return $event;
    }

    /** Além de 180 dias some; aquém fica. */
    public function test_prunes_only_events_older_than_the_window(): void
    {
        $old = $this->eventAgedDays(181);
        $recent = $this->eventAgedDays(30);

        $this->artisan('email-events:prune')->assertSuccessful();

        $this->assertDatabaseMissing('email_events', ['id' => $old->id]);
        $this->assertDatabaseHas('email_events', ['id' => $recent->id]);
    }

    /** --days encurta a janela. */
    public function test_days_option_overrides_the_window(): void
    {
        $event = $this->eventAgedDays(10);

        $this->artisan('email-events:prune', ['--days' => 7])->assertSuccessful();

        $this->assertDatabaseMissing('email_events', ['id' => $event->id]);
    }
}
