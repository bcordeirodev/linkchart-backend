<?php

namespace Tests\Unit\Services\Links;

use App\Contracts\Repositories\LinkRepositoryInterface;
use App\DTOs\CreatePublicLinkDTO;
use App\Models\Link;
use App\Services\Links\LinkService;
use Illuminate\Database\UniqueConstraintViolationException;
use Mockery;
use Tests\TestCase;

/**
 * Guards the TOCTOU fix in LinkService slug creation.
 *
 * Between slugExists() and the INSERT, a concurrent request can claim the
 * same slug; the resulting unique-index violation used to escape as a raw
 * QueryException (HTTP 500). Expected behavior now:
 *
 *   - AUTO-generated slug: the collision is caught and the create is retried
 *     exactly ONCE with a freshly generated slug; a second collision rethrows.
 *   - CUSTOM (user-chosen) slug: no silent retry — the collision surfaces as
 *     the same InvalidArgumentException("Slug personalizado já está em uso.")
 *     the pre-check raises, so the user still gets the "already taken" error.
 *
 * The repository is mocked because a real cross-request race cannot be
 * scripted deterministically against the DB; Laravel maps unique violations
 * of every driver to UniqueConstraintViolationException, which is what the
 * mock throws.
 */
class LinkServiceSlugCollisionTest extends TestCase
{
    /**
     * Builds the driver-agnostic exception Laravel raises for a violated
     * unique index on links.slug.
     */
    private function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(
            'testing',
            'insert into "links" ("slug") values (?)',
            ['collided'],
            new \Exception('UNIQUE constraint failed: links.slug')
        );
    }

    /**
     * A guest public-link DTO without a custom slug (auto-generation path).
     */
    private function autoSlugDTO(): CreatePublicLinkDTO
    {
        return new CreatePublicLinkDTO(
            original_url: 'https://example.com/destino',
            title: null,
            slug: null,
            is_active: true,
            user_id: null,
        );
    }

    /**
     * On a unique violation with an auto-generated slug, the create is
     * retried once with a NEW slug and the resulting link is returned.
     */
    public function test_auto_slug_collision_retries_once_with_a_fresh_slug(): void
    {
        $attemptedSlugs = [];

        $repository = Mockery::mock(LinkRepositoryInterface::class);
        $repository->shouldReceive('slugExists')->andReturn(false);
        $repository->shouldReceive('create')
            ->twice()
            ->andReturnUsing(function (array $data) use (&$attemptedSlugs) {
                $attemptedSlugs[] = $data['slug'];

                if (count($attemptedSlugs) === 1) {
                    throw $this->uniqueViolation();
                }

                $link = new Link($data);
                $link->id = 4242;

                return $link;
            });

        $link = (new LinkService($repository))->createPublicLink($this->autoSlugDTO());

        $this->assertCount(2, $attemptedSlugs);
        $this->assertNotSame($attemptedSlugs[0], $attemptedSlugs[1], 'retry must use a freshly generated slug');
        $this->assertSame($attemptedSlugs[1], $link->slug);
    }

    /**
     * Only ONE retry is attempted: a second consecutive collision propagates
     * the exception instead of looping.
     */
    public function test_auto_slug_collision_is_retried_only_once(): void
    {
        $repository = Mockery::mock(LinkRepositoryInterface::class);
        $repository->shouldReceive('slugExists')->andReturn(false);
        $repository->shouldReceive('create')
            ->twice()
            ->andThrow($this->uniqueViolation());

        $this->expectException(UniqueConstraintViolationException::class);

        (new LinkService($repository))->createPublicLink($this->autoSlugDTO());
    }

    /**
     * A user-chosen slug that loses the race gets the SAME "already in use"
     * error the pre-check produces — never a silent replacement slug, and
     * never a raw QueryException.
     */
    public function test_custom_slug_collision_surfaces_already_in_use_error_without_retry(): void
    {
        $repository = Mockery::mock(LinkRepositoryInterface::class);
        $repository->shouldReceive('slugExists')->andReturn(false); // pre-check passes: race window
        $repository->shouldReceive('create')
            ->once()
            ->andThrow($this->uniqueViolation());

        $dto = new CreatePublicLinkDTO(
            original_url: 'https://example.com/destino',
            title: null,
            slug: 'meu-slug-custom',
            is_active: true,
            user_id: null,
        );

        try {
            (new LinkService($repository))->createPublicLink($dto);
            $this->fail('Expected InvalidArgumentException for the custom-slug race');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Slug personalizado já está em uso.', $e->getMessage());
        }
    }
}
