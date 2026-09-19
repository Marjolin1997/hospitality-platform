<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcceptStaffInvitationRequest;
use App\Http\Requests\Api\V1\CreateStaffInvitationRequest;
use App\Models\Business;
use App\Services\Authorization\ManageStaffInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class StaffInvitationController extends Controller
{
    public function __construct(private readonly ManageStaffInvitation $invitations) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->invitations->list(app(Business::class)),
        ]);
    }

    public function store(CreateStaffInvitationRequest $request): JsonResponse
    {
        $invitation = $this->invitations->create(
            app(Business::class),
            $request->validated(),
            (int) $request->user()->id,
        );

        return response()->json(['data' => $invitation], 201);
    }

    public function revoke(Request $request, string $invitation): JsonResponse
    {
        $this->invitations->revoke(
            app(Business::class),
            $invitation,
            (int) $request->user()->id,
        );

        return response()->json(['message' => 'Staff invitation revoked.']);
    }

    public function preview(string $token): JsonResponse
    {
        return response()->json([
            'data' => $this->invitations->preview($token),
        ]);
    }

    public function accept(AcceptStaffInvitationRequest $request, string $token): JsonResponse
    {
        $user = $this->invitations->accept($token, $request->validated());

        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();

        return response()->json([
            'data' => [
                'user_id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
