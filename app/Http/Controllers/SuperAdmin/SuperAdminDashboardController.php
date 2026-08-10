<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Office;
use App\Models\User;
use App\Support\Presenters\DocumentPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * §4 Super Admin Panel. System-wide.
 */
class SuperAdminDashboardController extends Controller
{
    public function __construct(private readonly DocumentPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('super-admin/dashboard', [
            'stats' => [
                // Archived rows are counted deliberately: filing a completed
                // document away must not make the work disappear from totals.
                'documents' => Document::query()->count(),
                'open' => Document::query()->active()->stillOpen()->count(),
                'overdue' => Document::query()->active()->overdue()->count(),
                'offices' => Office::query()->active()->count(),
                'users' => User::query()->active()->count(),
            ],
            'recent' => Document::query()
                ->active()
                ->with(['documentType:id,name', 'openMovement.toOffice:id,name'])
                ->orderByDesc('documents.created_at')
                ->limit(15)
                ->get()
                ->map(fn (Document $document) => $this->presenter->listItem($document))
                ->all(),
        ]);
    }
}
