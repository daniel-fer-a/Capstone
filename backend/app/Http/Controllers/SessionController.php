<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Model02Access;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:1024', 'not_regex:/\\x00/'],
        ]);
        if (!Auth::guard('web')->attempt($data)) {
            throw ValidationException::withMessages(['email' => 'El correo o la contraseña no son correctos.']);
        }
        $request->session()->regenerate();

        return $this->current($request);
    }

    public function current(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();
        abort_unless($user, 401);
        $access = app(Model02Access::class);

        return response()->json(['data' => [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'can_review' => $access->reviewerHasPermission($user->id, 'admissions.read'),
            'can_start_review' => $access->reviewerHasPermission($user->id, 'admissions.read')
                && $access->reviewerHasPermission($user->id, 'admissions.review.start'),
            'can_sponsor' => $access->effectiveGlobalAssignments($user->id, now())->where('ra.role_code', 'socio')->exists(),
            'can_record_decision' => $access->reviewerHasPermission($user->id, 'admissions.read')
                && $access->reviewerHasPermission($user->id, 'admissions.decisions.record'),
        ]])->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada.'])->header('Cache-Control', 'no-store');
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255']]);
        try {
            Password::sendResetLink($data);
        } catch (\Throwable $exception) {
            Log::warning('auth.reset_delivery_failed', ['exception_type' => get_class($exception)]);
        }

        return response()->json(['message' => 'Si existe una cuenta con ese correo, recibirás instrucciones para recuperar el acceso.'])->header('Cache-Control', 'no-store');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12)->letters()->numbers(),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) $fail('Usa una contraseña más corta.');
                    if (is_string($value) && str_contains($value, "\0")) $fail('La contraseña contiene un carácter no admitido.');
                }],
        ]);
        $status = Password::reset($data, function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete();
            });
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['token' => 'El enlace no es válido o ya expiró. Solicita uno nuevo.']);
        }

        return response()->json(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.'])->header('Cache-Control', 'no-store');
    }
}
