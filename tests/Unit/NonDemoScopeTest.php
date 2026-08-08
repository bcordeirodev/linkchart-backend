<?php

namespace Tests\Unit;

use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scope canônico de exclusão de demo: is_demo = false E dono fora de
 * User::DEMO_ACCOUNT_IDS. Toda query do módulo admin passa por ele.
 */
class NonDemoScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_demo_excludes_demo_links_and_demo_accounts(): void
    {
        $real = User::factory()->create();
        $demoAccount = User::factory()->create(['id' => User::DEMO_ACCOUNT_IDS[0]]);

        Link::factory()->create(['user_id' => $real->id, 'is_demo' => false]);   // conta
        Link::factory()->create(['user_id' => $real->id, 'is_demo' => true]);    // fora: is_demo
        Link::factory()->create(['user_id' => $demoAccount->id, 'is_demo' => false]); // fora: conta demo

        $this->assertSame(1, Link::nonDemo()->count());
    }

    /**
     * `links.user_id` é NULLABLE (encurtador público). Como `NULL NOT IN (...)`
     * avalia para NULL em SQL, a versão antiga do scope descartava TODO link
     * anônimo das métricas globais. Anônimo é tráfego real: tem que contar.
     */
    public function test_non_demo_counts_anonymous_links(): void
    {
        $real = User::factory()->create();

        Link::factory()->create(['user_id' => $real->id, 'is_demo' => false]); // conta
        Link::factory()->create(['user_id' => null, 'is_demo' => false]);      // conta: anônimo
        Link::factory()->create(['user_id' => null, 'is_demo' => true]);       // fora: is_demo

        $this->assertSame(2, Link::nonDemo()->count());
    }

    public function test_non_demo_survives_a_join(): void
    {
        $real = User::factory()->create();
        Link::factory()->create(['user_id' => $real->id, 'is_demo' => false]);

        // Colunas qualificadas: sem "links." o JOIN com users quebraria por ambiguidade.
        $count = Link::nonDemo()
            ->join('users', 'users.id', '=', 'links.user_id')
            ->count();

        $this->assertSame(1, $count);
    }
}
