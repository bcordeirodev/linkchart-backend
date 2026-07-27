<?php

namespace Tests\Feature;

use App\Models\BioPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature tests for the authenticated avatar endpoints of the "link-in-bio"
 * module: POST /api/bio/avatar and DELETE /api/bio/avatar
 * (BioPageController::uploadAvatar / removeAvatar).
 *
 * Mirrors the auth/setup pattern used by {@see BioPageManagementTest}. Uses
 * `Storage::fake('public')` throughout — the default disk configured by
 * `config('bio.avatar_disk')` — so no real files ever touch the filesystem.
 */
class BioPageAvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        $this->user = User::factory()->create([
            'email_verified' => true,
            'email_verified_at' => now(),
        ]);
        $this->token = auth()->guard('api')->login($this->user);
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_upload_sets_avatar_url_and_stores_file(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id, 'handle' => 'joaosilva']);

        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200)->size(500);

        $response = $this->post('/api/bio/avatar', ['avatar' => $file], $this->auth());

        $response->assertOk();
        $avatarUrl = $response->json('data.avatar_url');
        $this->assertNotNull($avatarUrl);
        $this->assertStringContainsString('bio-avatars/', $avatarUrl);

        $page = BioPage::where('user_id', $this->user->id)->first();
        $this->assertNotNull($page->avatar_path);
        Storage::disk('public')->assertExists($page->avatar_path);
    }

    public function test_upload_replacing_avatar_deletes_previous_file(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id, 'handle' => 'joaosilva']);

        $first = UploadedFile::fake()->image('first.jpg', 200, 200);
        $this->post('/api/bio/avatar', ['avatar' => $first], $this->auth())->assertOk();
        $firstPath = BioPage::where('user_id', $this->user->id)->first()->avatar_path;

        $second = UploadedFile::fake()->image('second.png', 200, 200);
        $this->post('/api/bio/avatar', ['avatar' => $second], $this->auth())->assertOk();
        $secondPath = BioPage::where('user_id', $this->user->id)->first()->avatar_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_upload_uses_non_enumerable_filename(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id]);

        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);
        $response = $this->post('/api/bio/avatar', ['avatar' => $file], $this->auth());

        $page = BioPage::where('user_id', $this->user->id)->first();
        $filename = pathinfo($page->avatar_path, PATHINFO_FILENAME);

        $this->assertNotSame((string) $this->user->id, $filename);
        $this->assertGreaterThanOrEqual(20, strlen($filename));
    }

    public function test_delete_removes_file_and_nulls_avatar_url(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id, 'handle' => 'joaosilva']);
        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);
        $this->post('/api/bio/avatar', ['avatar' => $file], $this->auth())->assertOk();
        $path = BioPage::where('user_id', $this->user->id)->first()->avatar_path;

        $response = $this->delete('/api/bio/avatar', [], $this->auth());

        $response->assertOk();
        $this->assertNull($response->json('data.avatar_url'));
        Storage::disk('public')->assertMissing($path);

        $page = BioPage::where('user_id', $this->user->id)->first();
        $this->assertNull($page->avatar_url);
        $this->assertNull($page->avatar_path);
    }

    public function test_delete_without_existing_avatar_is_a_noop_success(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id, 'handle' => 'joaosilva']);

        $response = $this->delete('/api/bio/avatar', [], $this->auth());

        $response->assertOk();
        $this->assertNull($response->json('data.avatar_url'));
    }

    public function test_upload_rejects_non_image_file(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id]);

        $file = UploadedFile::fake()->create('document.pdf', 500, 'application/pdf');

        $this->post('/api/bio/avatar', ['avatar' => $file], $this->auth())->assertStatus(422);
    }

    public function test_upload_rejects_mime_type_outside_allowed_list(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id]);

        // GIF is a valid image but not in the jpeg/png/webp allow-list.
        $file = UploadedFile::fake()->image('avatar.gif', 200, 200);

        $this->post('/api/bio/avatar', ['avatar' => $file], $this->auth())->assertStatus(422);
    }

    public function test_upload_rejects_file_larger_than_2mb(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id]);

        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200)->size(2049);

        $this->post('/api/bio/avatar', ['avatar' => $file], $this->auth())->assertStatus(422);
    }

    public function test_upload_rejects_dimensions_smaller_than_100x100(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id]);

        $file = UploadedFile::fake()->image('avatar.jpg', 99, 99);

        $this->post('/api/bio/avatar', ['avatar' => $file], $this->auth())->assertStatus(422);
    }

    public function test_upload_rejects_when_bio_page_does_not_exist_yet(): void
    {
        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $this->post('/api/bio/avatar', ['avatar' => $file], $this->auth())->assertStatus(422);
    }

    public function test_delete_rejects_when_bio_page_does_not_exist_yet(): void
    {
        $this->delete('/api/bio/avatar', [], $this->auth())->assertStatus(422);
    }

    public function test_public_shape_includes_avatar_url(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id, 'handle' => 'joaosilva']);
        $file = UploadedFile::fake()->image('avatar.jpg', 200, 200);
        $this->post('/api/bio/avatar', ['avatar' => $file], $this->auth())->assertOk();

        $response = $this->getJson('/api/public/bio/joaosilva');

        $response->assertOk()->assertJsonStructure(['data' => ['avatar_url']]);
        $this->assertNotNull($response->json('data.avatar_url'));
    }

    public function test_public_shape_has_null_avatar_url_when_no_avatar_uploaded(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id, 'handle' => 'joaosilva']);

        $response = $this->getJson('/api/public/bio/joaosilva');

        $response->assertOk();
        $this->assertNull($response->json('data.avatar_url'));
    }

    public function test_management_shape_includes_avatar_url_key_when_no_avatar(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id]);

        $response = $this->getJson('/api/bio', $this->auth());

        $response->assertOk()->assertJsonStructure(['data' => ['avatar_url']]);
        $this->assertNull($response->json('data.avatar_url'));
    }

    public function test_upload_returns_429_after_10_requests_per_minute(): void
    {
        BioPage::factory()->create(['user_id' => $this->user->id]);

        for ($i = 0; $i < 10; $i++) {
            $file = UploadedFile::fake()->image("avatar{$i}.jpg", 200, 200);
            $this->post('/api/bio/avatar', ['avatar' => $file], $this->auth())->assertOk();
        }

        $file = UploadedFile::fake()->image('avatar-over.jpg', 200, 200);
        $this->post('/api/bio/avatar', ['avatar' => $file], $this->auth())->assertStatus(429);
    }
}
