<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Document;
use App\Models\User;
use App\Support\Reporting\ActivityReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The lists behind the Reports page's "User activity" card (client request,
 * 2026-09-25) -- by document and by person, each row opening onto its trail.
 *
 * JSON, fetched when the card asks: a year of trails for every document an
 * office touched is far too much to put on the page up front, and a trail is
 * only wanted for the one row somebody clicks. Admin and Super Admin only,
 * like the card itself, and every answer is scoped through visibleTo().
 */
class ReportActivityController extends Controller
{
    public function __construct(private readonly ActivityReport $activity) {}

    public function documents(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);

        return response()->json($this->activity->documents(
            $viewer,
            ActivityReport::since(ActivityReport::months($request)),
            $request->string('q')->value(),
            $request->integer('page', 1),
        ));
    }

    public function document(Request $request, Document $document): JsonResponse
    {
        $viewer = $this->viewer($request);

        // 404, not 403: an office that cannot see a document should not learn
        // from this endpoint that its number exists.
        abort_unless($this->activity->canSee($viewer, $document), 404);

        return response()->json([
            'steps' => $this->activity->documentTrail($document),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $viewer = $this->viewer($request);

        return response()->json($this->activity->users(
            $viewer,
            ActivityReport::since(ActivityReport::months($request)),
            $request->string('q')->value(),
            $request->integer('page', 1),
        ));
    }

    public function user(Request $request, User $user): JsonResponse
    {
        $viewer = $this->viewer($request);

        return response()->json($this->activity->userTrail(
            $viewer,
            $user,
            ActivityReport::since(ActivityReport::months($request)),
        ));
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->atLeast(Role::Admin), 403);

        return $user;
    }
}
