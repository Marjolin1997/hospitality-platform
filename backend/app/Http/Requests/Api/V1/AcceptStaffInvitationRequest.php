<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

final class AcceptStaffInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $existingUser = $this->invitedAccountExists();

        return [
            'name' => [$existingUser ? 'nullable' : 'required', 'string', 'max:120'],
            'password' => $existingUser
                ? ['required', 'string', 'max:255']
                : [
                    'required',
                    'confirmed',
                    Password::min(12)->mixedCase()->letters()->numbers()->symbols(),
                ],
            'password_confirmation' => $existingUser
                ? ['nullable', 'string', 'max:255']
                : ['required', 'string'],
        ];
    }

    private function invitedAccountExists(): bool
    {
        $token = (string) $this->route('token');
        if ($token === '') {
            return false;
        }

        $email = DB::table('staff_invitations')
            ->where('token_hash', hash('sha256', $token))
            ->value('email');

        if (! is_string($email) || $email === '') {
            return false;
        }

        return DB::table('users')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->exists();
    }
}
