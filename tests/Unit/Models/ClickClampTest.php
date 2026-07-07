<?php

namespace Tests\Unit\Models;

use App\Models\Click;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for Click::clampToColumnLimits — the defensive guard that keeps
 * an over-long value in any bounded varchar column from aborting the whole click
 * insert (SQLSTATE[22001]). Pure array logic, no database.
 */
class ClickClampTest extends TestCase
{
    public function test_truncates_value_exceeding_its_column_limit(): void
    {
        $attributes = ['accept_language' => str_repeat('a', 250)];

        $clamped = Click::clampToColumnLimits($attributes);

        // accept_language column is varchar(100).
        $this->assertSame(100, mb_strlen($clamped['accept_language']));
    }

    public function test_leaves_value_within_its_limit_untouched(): void
    {
        $attributes = ['browser' => 'Chrome'];

        $clamped = Click::clampToColumnLimits($attributes);

        $this->assertSame('Chrome', $clamped['browser']);
    }

    public function test_preserves_a_full_facebook_wrapper_referer(): void
    {
        // The real failing case: a Facebook in-app-browser l.php wrapper URL
        // (~400 chars) that overflowed the old varchar(255). It must now survive
        // intact under the widened varchar(2048) limit.
        $facebookReferer = 'https://lm.facebook.com/l.php?u=https%3A%2F%2Fredirect.linkcharts.com.br%2Fg3iila%3Ffbclid%3D'
            .str_repeat('A', 120).'&h='.str_repeat('B', 180);
        $attributes = ['referer' => $facebookReferer];

        $clamped = Click::clampToColumnLimits($attributes);

        $this->assertSame($facebookReferer, $clamped['referer']);
    }

    public function test_clamps_a_pathological_referer_to_the_index_safe_limit(): void
    {
        // A referer longer than any real URL is clamped rather than allowed to
        // exceed the (link_id, referer) btree index row-size limit.
        $attributes = ['referer' => 'https://x.test/'.str_repeat('q', 5000)];

        $clamped = Click::clampToColumnLimits($attributes);

        // referer column is varchar(2048).
        $this->assertSame(2048, mb_strlen($clamped['referer']));
    }

    public function test_leaves_non_string_and_null_values_untouched(): void
    {
        $attributes = ['link_id' => 273, 'latitude' => null, 'city' => 'Altos'];

        $clamped = Click::clampToColumnLimits($attributes);

        $this->assertSame(273, $clamped['link_id']);
        $this->assertNull($clamped['latitude']);
        $this->assertSame('Altos', $clamped['city']);
    }

    public function test_truncates_by_characters_not_bytes_for_multibyte_values(): void
    {
        // 120 accented (multibyte) characters into a varchar(100) column.
        $attributes = ['holiday_name' => str_repeat('ç', 120)];

        $clamped = Click::clampToColumnLimits($attributes);

        // holiday_name column is varchar(100); must clamp to 100 characters,
        // never split a multibyte sequence.
        $this->assertSame(100, mb_strlen($clamped['holiday_name']));
        $this->assertSame(str_repeat('ç', 100), $clamped['holiday_name']);
    }
}
