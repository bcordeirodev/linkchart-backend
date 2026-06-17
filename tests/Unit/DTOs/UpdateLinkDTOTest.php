<?php

namespace Tests\Unit\DTOs;

use App\DTOs\UpdateLinkDTO;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Covers the present-field tracking that lets a partial update CLEAR a nullable
 * column. Regression guard for the bug where sending `click_limit: 0` could
 * never remove an existing cap (the null was stripped by toArray()).
 */
class UpdateLinkDTOTest extends TestCase
{
    public function test_absent_field_is_not_emitted(): void
    {
        $dto = UpdateLinkDTO::fromRequest(Request::create('/', 'PUT', [
            'title' => 'New title',
        ]));

        $array = $dto->toArray();

        $this->assertSame(['title' => 'New title'], $array);
        $this->assertArrayNotHasKey('click_limit', $array);
    }

    public function test_click_limit_zero_clears_the_cap(): void
    {
        $dto = UpdateLinkDTO::fromRequest(Request::create('/', 'PUT', [
            'click_limit' => 0,
        ]));

        $array = $dto->toArray();

        $this->assertArrayHasKey('click_limit', $array);
        $this->assertNull($array['click_limit']);
    }

    public function test_click_limit_value_is_kept_as_int(): void
    {
        $dto = UpdateLinkDTO::fromRequest(Request::create('/', 'PUT', [
            'click_limit' => '5',
        ]));

        $this->assertSame(5, $dto->toArray()['click_limit']);
    }

    public function test_present_empty_utm_clears_the_value(): void
    {
        $dto = UpdateLinkDTO::fromRequest(Request::create('/', 'PUT', [
            'utm_source' => '',
        ]));

        $array = $dto->toArray();

        $this->assertArrayHasKey('utm_source', $array);
        $this->assertNull($array['utm_source']);
    }

    public function test_is_active_false_is_emitted(): void
    {
        $dto = UpdateLinkDTO::fromRequest(Request::create('/', 'PUT', [
            'is_active' => false,
        ]));

        $array = $dto->toArray();

        $this->assertArrayHasKey('is_active', $array);
        $this->assertFalse($array['is_active']);
    }

    public function test_direct_construction_keeps_legacy_null_stripping(): void
    {
        // No $presentFields → backward-compatible behaviour: nulls are dropped.
        $dto = new UpdateLinkDTO(title: 'X', click_limit: null);

        $this->assertSame(['title' => 'X'], $dto->toArray());
    }
}
