<?php

namespace Tests\Unit\Models;

use App\Models\Link;
use Tests\TestCase;

class LinkShortDomainTest extends TestCase
{
    public function test_get_shorted_url_uses_default_when_short_domain_is_null(): void
    {
        config(['app.redirect_url' => 'https://linkcharts.com.br']);
        $link = new Link(['slug' => 'abc123', 'short_domain' => null]);
        $this->assertEquals('https://linkcharts.com.br/abc123', $link->getShortedUrl());
    }

    public function test_get_shorted_url_uses_custom_domain_when_short_domain_is_set(): void
    {
        config(['app.redirect_url' => 'https://linkcharts.com.br']);
        $link = new Link(['slug' => 'abc123', 'short_domain' => 'acme.linkcharts.com.br']);
        $this->assertEquals('https://acme.linkcharts.com.br/abc123', $link->getShortedUrl());
    }
}
