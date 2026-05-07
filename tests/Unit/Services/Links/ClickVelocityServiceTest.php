<?php

namespace Tests\Unit\Services\Links;

use App\Services\Links\ClickVelocityService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ClickVelocityServiceTest extends TestCase
{
    /**
     * @test
     */
    public function test_viral_rank_is_cold_when_below_all_thresholds(): void
    {
        Redis::shouldReceive('pipeline')
            ->once()
            ->andReturnUsing(function (callable $cb) {
                $pipe = \Mockery::mock();
                $pipe->shouldReceive('incr')->twice()->andReturn(null);
                $pipe->shouldReceive('expire')->twice()->andReturn(null);
                $pipe->shouldReceive('getset')->once()->andReturn(null);
                $cb($pipe);
                return [3, true, 10, true, null];
            });

        $service = new ClickVelocityService();
        $result  = $service->record(1);

        $this->assertEquals('cold', $result['viral_rank']);
        $this->assertNull($result['seconds_since_last_click']);
    }

    /**
     * @test
     */
    public function test_viral_rank_is_viral_when_5min_count_exceeds_threshold(): void
    {
        $prevTs = now()->timestamp - 5;

        Redis::shouldReceive('pipeline')
            ->once()
            ->andReturnUsing(function (callable $cb) use ($prevTs) {
                $pipe = \Mockery::mock();
                $pipe->shouldReceive('incr')->twice()->andReturn(null);
                $pipe->shouldReceive('expire')->twice()->andReturn(null);
                $pipe->shouldReceive('getset')->once()->andReturn(null);
                $cb($pipe);
                return [60, true, 200, true, (string) $prevTs];
            });

        $service = new ClickVelocityService();
        $result  = $service->record(1);

        $this->assertEquals('viral', $result['viral_rank']);
        $this->assertGreaterThanOrEqual(4, $result['seconds_since_last_click']);
    }

    /**
     * @test
     */
    public function test_viral_rank_is_trending_when_5min_count_exceeds_trending_threshold(): void
    {
        Redis::shouldReceive('pipeline')
            ->once()
            ->andReturnUsing(function (callable $cb) {
                $pipe = \Mockery::mock();
                $pipe->shouldReceive('incr')->twice()->andReturn(null);
                $pipe->shouldReceive('expire')->twice()->andReturn(null);
                $pipe->shouldReceive('getset')->once()->andReturn(null);
                $cb($pipe);
                return [25, true, 50, true, null];
            });

        $service = new ClickVelocityService();
        $result  = $service->record(1);

        $this->assertEquals('trending', $result['viral_rank']);
    }

    /**
     * @test
     */
    public function test_viral_rank_is_warming_when_only_1h_count_exceeds_threshold(): void
    {
        Redis::shouldReceive('pipeline')
            ->once()
            ->andReturnUsing(function (callable $cb) {
                $pipe = \Mockery::mock();
                $pipe->shouldReceive('incr')->twice()->andReturn(null);
                $pipe->shouldReceive('expire')->twice()->andReturn(null);
                $pipe->shouldReceive('getset')->once()->andReturn(null);
                $cb($pipe);
                return [5, true, 120, true, null];
            });

        $service = new ClickVelocityService();
        $result  = $service->record(1);

        $this->assertEquals('warming', $result['viral_rank']);
    }

    /**
     * @test
     */
    public function test_seconds_since_last_click_is_computed_from_previous_timestamp(): void
    {
        $prevTs = now()->timestamp - 30;

        Redis::shouldReceive('pipeline')
            ->once()
            ->andReturnUsing(function (callable $cb) use ($prevTs) {
                $pipe = \Mockery::mock();
                $pipe->shouldReceive('incr')->twice()->andReturn(null);
                $pipe->shouldReceive('expire')->twice()->andReturn(null);
                $pipe->shouldReceive('getset')->once()->andReturn(null);
                $cb($pipe);
                return [1, true, 1, true, (string) $prevTs];
            });

        $service = new ClickVelocityService();
        $result  = $service->record(42);

        $this->assertNotNull($result['seconds_since_last_click']);
        $this->assertGreaterThanOrEqual(29, $result['seconds_since_last_click']);
        $this->assertLessThanOrEqual(31, $result['seconds_since_last_click']);
    }
}
