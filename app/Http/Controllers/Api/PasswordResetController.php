<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    /**
     * Balikannya SELALU 200 dengan pesan yang sama, mau emailnya kedaftar atau
     * nggak.
     *
     * Ini disengaja: kalau dibedain ("email nggak terdaftar"), endpoint ini jadi
     * alat buat nebak-nebak siapa aja yang punya akun di sistem — cukup coba
     * ratusan email, yang balikannya beda berarti kedaftar. Mobile: tampilin
     * layar "cek email kamu" apa pun hasilnya.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->validated());

        return response()->json([
            'message' => 'Kalau email itu terdaftar, link reset password udah dikirim ke sana.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();

                // Semua token lama dicabut. Kalau nggak, sesi yang mungkin udah
                // kepegang orang lain tetap hidup walau passwordnya udah diganti —
                // padahal itu justru alasan orang me-reset password.
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Token reset nggak valid atau udah kadaluarsa. Minta link baru ya.',
            ], 422);
        }

        return response()->json(['message' => 'Password berhasil diubah. Silakan login lagi.']);
    }
}
