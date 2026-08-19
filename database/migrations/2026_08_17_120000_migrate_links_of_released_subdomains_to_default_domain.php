<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill (data-only, expand/contract-safe): heals links orphaned by
 * subdomain releases that happened BEFORE the release flow started migrating
 * links (SubdomainController::destroy, 2026-08-17). Those links carry a
 * `short_domain` whose subdomain is no longer active for their owner, so
 * their short URLs point at a host the redirect blocks with
 * `subdomain_not_found`. Reverting them to the default domain
 * (`short_domain = null`) restores a working short URL; click history is
 * untouched (clicks reference link_id).
 *
 * Cache note: this UPDATE bypasses model events, so slug-cache entries are
 * not forgotten here. Acceptable: the cache TTL is 10 minutes and the stale
 * value only affects the displayed short URL, which was already broken.
 *
 * No down(): the pre-migration state (broken hostnames) is not worth
 * restoring, and the affected rows are not identifiable after the fact.
 */
return new class extends Migration
{
    /**
     * Null out `short_domain` on links whose hostname has no active
     * subdomain owned by the same user.
     */
    public function up(): void
    {
        $domain = config('app.domain');

        DB::update(
            <<<'SQL'
            UPDATE links
            SET short_domain = NULL
            WHERE short_domain IS NOT NULL
              AND NOT EXISTS (
                SELECT 1
                FROM user_subdomains us
                WHERE us.user_id = links.user_id
                  AND us.status = 'active'
                  AND links.short_domain = us.subdomain || '.' || ?
              )
            SQL,
            [$domain]
        );
    }
};
