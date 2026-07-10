<?php

namespace App\Http\Controllers\Links;

use App\Contracts\Services\TagServiceInterface;
use App\Http\Controllers\BaseController;
use App\Http\Requests\CreateTagRequest;
use App\Http\Requests\UpdateTagRequest;
use App\Http\Resources\TagResource;
use Illuminate\Http\JsonResponse;

/**
 * RESTful controller for authenticated tag management (CRUD).
 *
 * Owns the /api/tags/* route family (all under api.auth:api + verified
 * middleware, see routes/api.php). Every action is scoped to
 * `auth()->guard('api')->id()` via TagServiceInterface — a tag id belonging
 * to another user always resolves to a 404, never a 403, to avoid confirming
 * the tag's existence to a non-owner.
 *
 * Routes overview:
 *   GET    /api/tags        → index
 *   POST   /api/tags        → store
 *   PUT    /api/tags/{id}   → update
 *   DELETE /api/tags/{id}   → destroy
 *
 * Depends on: TagServiceInterface (injected).
 */
class TagController extends BaseController
{
    protected TagServiceInterface $tagService;

    public function __construct(TagServiceInterface $tagService)
    {
        $this->tagService = $tagService;
    }

    /**
     * GET /api/tags
     *
     * Return all tags belonging to the authenticated user.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — TagService filters by auth user id.
     *
     * Response shape: { data: TagResource[] }
     */
    public function index(): JsonResponse
    {
        try {
            $tags = $this->tagService->getAllUserTags();

            return response()->json(['data' => TagResource::collection($tags)]);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao buscar tags.', $e);
        }
    }

    /**
     * POST /api/tags
     *
     * Create a new tag for the authenticated user. Validates name/color via
     * CreateTagRequest; TagService additionally enforces a 20-tag-per-user
     * cap and per-user name uniqueness, both surfaced here as 422.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     *
     * Response shape: { message, data: TagResource } (201)
     *
     * @throws \Illuminate\Validation\ValidationException (handled by CreateTagRequest)
     */
    public function store(CreateTagRequest $request): JsonResponse
    {
        try {
            $tag = $this->tagService->createTag($request->validated());

            return response()->json([
                'message' => 'Tag criada com sucesso.',
                'data' => new TagResource($tag),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Dados inválidos.',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao criar tag.', $e);
        }
    }

    /**
     * PUT /api/tags/{id}
     *
     * Update a tag owned by the authenticated user.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — verifies ownership before updating.
     *
     * Response shape: { message, data: TagResource } (200)
     *
     * @param  string  $id  Numeric tag ID (enforced by route constraint [0-9]+).
     *
     * @throws \Illuminate\Validation\ValidationException (handled by UpdateTagRequest)
     */
    public function update(UpdateTagRequest $request, string $id): JsonResponse
    {
        try {
            $existing = $this->tagService->getUserTag($id);
            if (! $existing) {
                return response()->json(['message' => 'Tag não encontrada ou você não tem permissão para editá-la.'], 404);
            }

            $tag = $this->tagService->updateTag($id, $request->validated());

            return response()->json([
                'message' => 'Tag atualizada com sucesso.',
                'data' => new TagResource($tag),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Dados inválidos.',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao atualizar tag.', $e);
        }
    }

    /**
     * DELETE /api/tags/{id}
     *
     * Permanently delete a tag owned by the authenticated user. Detaching it
     * from any linked links is handled at the database level by the
     * `link_tag` pivot's `cascadeOnDelete()` foreign key.
     *
     * Middleware: api.auth:api, verified
     * Auth: required
     * Owner check: yes — verifies ownership before deleting.
     *
     * Response shape: { message } (200)
     *
     * @param  string  $id  Numeric tag ID (enforced by route constraint [0-9]+).
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $existing = $this->tagService->getUserTag($id);
            if (! $existing) {
                return response()->json(['message' => 'Tag não encontrada ou você não tem permissão para removê-la.'], 404);
            }

            $this->tagService->deleteTag($id);

            return response()->json(['message' => 'Tag removida com sucesso.']);
        } catch (\Exception $e) {
            return $this->serverError('Erro ao remover tag.', $e);
        }
    }
}
