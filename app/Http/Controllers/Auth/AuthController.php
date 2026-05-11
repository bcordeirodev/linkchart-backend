<?php

namespace App\Http\Controllers\Auth;

use App\Logging\AppLogger;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Authentication and user-profile controller.
 *
 * Handles registration, login/logout, JWT refresh, profile management, password
 * changes, and email-verification flows. Does not extend BaseController — uses
 * Illuminate\Routing\Controller directly because it does not need link-ownership
 * helpers.
 *
 * Route groups in routes/api.php:
 *   - `throttle:login` group  — register, login, verifyEmail, forgotPassword, resetPassword
 *   - `api.auth:api` only     — me, logout, checkEmailVerificationStatus, resendVerificationEmail
 *   - `api.auth:api, verified` — updateProfile, changePassword
 *
 * All responses are raw JSON (not wrapped by NormalizeApiResponse, which only
 * applies to the `api` route group containing links and analytics).
 *
 * Known issue: resendVerificationEmail carries NO rate limit despite sending
 * email — the throttle:login middleware does not cover that route. See audit
 * §14 for context.
 */
class AuthController extends Controller
{
    private EmailVerificationService $emailVerificationService;

    public function __construct(EmailVerificationService $emailVerificationService)
    {
        $this->emailVerificationService = $emailVerificationService;
    }

    /**
     * POST /api/auth/register
     *
     * Create a new user account, send an email verification message, and issue
     * a JWT so the client can make authenticated requests immediately (before
     * the email is verified).
     *
     * Middleware: throttle:login
     * Auth: not required
     * Owner check: no
     *
     * Response shape: { success, message, user, token, email_verification: { sent, message, email } } (201)
     *                 { error, message, errors } on validation failure (422)
     *                 { error, message, error_id } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:6|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation Error',
                    'message' => 'Dados inválidos fornecidos',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'email_verified' => false, // Novo usuário não verificado
            ]);

            // Enviar email de verificação
            $emailResult = $this->emailVerificationService->sendVerificationEmail($user, $request);

            // Gerar token JWT mesmo sem verificação (usuário pode usar o sistema)
            $token = JWTAuth::fromUser($user);

            AppLogger::authRegistration($user->id, $user->email);

            return response()->json([
                'success' => true,
                'message' => 'Usuário registrado com sucesso! Verifique seu email para ativar sua conta.',
                'user' => $user,
                'token' => $token,
                'email_verification' => [
                    'sent' => $emailResult['success'],
                    'message' => $emailResult['message'],
                    'email' => $user->email,
                ],
            ], 201);

        } catch (\Exception $e) {
            AppLogger::authError('registration', $e);

            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao registrar usuário. Verifique os logs.',
                'error_id' => uniqid('reg_'),
            ], 500);
        }
    }

    /**
     * POST /api/auth/login
     *
     * Attempt credential-based authentication and return a JWT on success.
     * Logs failure events (including the attempted email) via AppLogger.
     *
     * Middleware: throttle:login (5 attempts/min per email or IP)
     * Auth: not required
     * Owner check: no
     *
     * Response shape: { success, message, token, user } (200)
     *                 { error, message } on invalid credentials (401)
     *                 { error, message, errors } on validation failure (422)
     *                 { error, message, error_id } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string|min:6',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation Error',
                    'message' => 'Dados inválidos fornecidos',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $credentials = $request->only('email', 'password');

            if (! $token = JWTAuth::attempt($credentials)) {
                AppLogger::authLoginFailure($credentials['email'], $request->ip(), 'invalid_credentials');

                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => 'Credenciais inválidas',
                ], 401);
            }

            $user = auth()->user();
            AppLogger::authLoginSuccess($user->id, $request->ip());

            return response()->json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'token' => $token,
                'user' => $user,
            ]);

        } catch (\Exception $e) {
            AppLogger::authError('login', $e);

            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao fazer login. Verifique os logs.',
                'error_id' => uniqid('login_'),
            ], 500);
        }
    }

    /**
     * POST /api/auth/logout
     *
     * Invalidate the current JWT so it cannot be reused.
     *
     * Middleware: api.auth:api
     * Auth: required (JWT)
     * Owner check: no
     *
     * Response shape: { success, message } (200)
     *                 { error, message } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => 'Logout realizado com sucesso',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao fazer logout',
            ], 500);
        }
    }

    /**
     * GET /api/me
     *
     * Return the authenticated user's model data.
     *
     * Middleware: api.auth:api
     * Auth: required (JWT)
     * Owner check: no
     *
     * Response shape: { success, user } (200)
     *                 { error, message } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
    {
        try {
            return response()->json([
                'success' => true,
                'user' => auth()->user(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao obter informações do usuário',
            ], 500);
        }
    }

    /**
     * POST /api/auth/refresh  (not currently listed in routes — available via JWT package)
     *
     * Issue a new JWT by refreshing the current (possibly expired) token.
     *
     * Auth: JWT (current token, may be expired within TTL grace period)
     * Owner check: no
     *
     * Response shape: { success, token } (200)
     *                 { error, message } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        try {
            $token = JWTAuth::refresh(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'token' => $token,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao renovar token',
            ], 500);
        }
    }

    /**
     * PUT /api/profile
     *
     * Update the authenticated user's name and email address.
     *
     * Middleware: api.auth:api, verified
     * Auth: required (JWT + verified email)
     * Owner check: no (operates on the authenticated user's own record)
     *
     * Response shape: { success, message, user } (200)
     *                 { error, message, errors } on validation failure (422)
     *                 { error, message, error_id } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateProfile(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email,'.auth()->id(),
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation Error',
                    'message' => 'Dados inválidos fornecidos',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = auth()->user();
            $user->update($request->only(['name', 'email']));

            return response()->json([
                'success' => true,
                'message' => 'Perfil atualizado com sucesso',
                'user' => $user->fresh(), // Retorna dados atualizados
            ]);

        } catch (\Exception $e) {
            AppLogger::authError('update_profile', $e);

            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao atualizar perfil. Verifique os logs.',
                'error_id' => uniqid('profile_'),
            ], 500);
        }
    }

    /**
     * PUT /api/change-password
     *
     * Verify the current password and replace it with a new one.
     *
     * Middleware: api.auth:api, verified
     * Auth: required (JWT + verified email)
     * Owner check: no (operates on the authenticated user's own record)
     *
     * Response shape: { success, message } (200)
     *                 { error, message } on wrong current password (422)
     *                 { error, message, errors } on validation failure (422)
     *                 { error, message, error_id } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function changePassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation Error',
                    'message' => 'Dados inválidos fornecidos',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = auth()->user();

            // Verificar senha atual
            if (! Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'error' => 'Invalid Password',
                    'message' => 'Senha atual incorreta',
                ], 422);
            }

            // Atualizar senha
            $user->update([
                'password' => Hash::make($request->new_password),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Senha alterada com sucesso',
            ]);

        } catch (\Exception $e) {
            AppLogger::authError('change_password', $e);

            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao alterar senha. Verifique os logs.',
                'error_id' => uniqid('pwd_'),
            ], 500);
        }
    }

    /**
     * POST /api/auth/verify-email
     *
     * Mark the user's email as verified using a 64-character one-time token
     * sent by email. Delegates to EmailVerificationService::verifyEmail.
     *
     * Middleware: throttle:login
     * Auth: not required
     * Owner check: no
     *
     * Response shape: service-defined { success, ... } (200 or 400)
     *                 { success: false, message, errors } on validation failure (422)
     *                 { success: false, message, error_id } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyEmail(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'token' => 'required|string|size:64',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token inválido',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $result = $this->emailVerificationService->verifyEmail($request->token);

            return response()->json($result, $result['success'] ? 200 : 400);

        } catch (\Exception $e) {
            AppLogger::authError('email_verification', $e);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao verificar email',
                'error_id' => uniqid('verify_'),
            ], 500);
        }
    }

    /**
     * POST /api/resend-verification-email
     *
     * Re-send the email verification message for the authenticated user, unless
     * the email is already verified.
     *
     * Middleware: api.auth:api (no verified, no throttle)
     * Auth: required (JWT)
     * Owner check: no (operates on the authenticated user's own record)
     *
     * NOTE: This action carries NO rate limit despite sending email. This is a
     * known gap surfaced in the audit (§14). A dedicated throttle should be
     * added in a follow-up.
     *
     * Response shape: service-defined { success, ... } (200 or 400)
     *                 { success: false, message, type: 'already_verified' } (400) if already done
     *                 { success: false, message, error_id } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendVerificationEmail(Request $request)
    {
        try {
            $user = auth()->user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado',
                ], 401);
            }

            if ($user->hasVerifiedEmail()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email já foi verificado',
                    'type' => 'already_verified',
                ], 400);
            }

            $result = $this->emailVerificationService->sendVerificationEmail($user, $request);

            return response()->json($result, $result['success'] ? 200 : 400);

        } catch (\Exception $e) {
            AppLogger::authError('resend_verification', $e);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao reenviar email de verificação',
                'error_id' => uniqid('resend_'),
            ], 500);
        }
    }

    /**
     * POST /api/auth/forgot-password
     *
     * Initiate the password-reset flow by sending a reset link to the given
     * email address. Always returns 200 to avoid leaking whether the email
     * exists in the system.
     *
     * Middleware: throttle:login
     * Auth: not required
     * Owner check: no
     *
     * Response shape: service-defined { success, message, ... } (200 always)
     *                 { success: false, message, errors } on validation failure (422)
     *                 { success: false, message, error_id } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function forgotPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email inválido',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $result = $this->emailVerificationService->sendPasswordResetEmail(
                $request->email,
                $request
            );

            return response()->json($result, 200); // Sempre 200 por segurança

        } catch (\Exception $e) {
            AppLogger::authError('forgot_password', $e);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao processar solicitação',
                'error_id' => uniqid('forgot_'),
            ], 500);
        }
    }

    /**
     * POST /api/auth/reset-password
     *
     * Complete the password-reset flow using a 64-character one-time token and
     * a new confirmed password.
     *
     * Middleware: throttle:login
     * Auth: not required
     * Owner check: no
     *
     * Response shape: service-defined { success, ... } (200 or 400)
     *                 { success: false, message, errors } on validation failure (422)
     *                 { success: false, message, error_id } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'token' => 'required|string|size:64',
                'password' => 'required|string|min:6|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $result = $this->emailVerificationService->resetPassword(
                $request->token,
                $request->password
            );

            return response()->json($result, $result['success'] ? 200 : 400);

        } catch (\Exception $e) {
            AppLogger::authError('reset_password', $e);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao redefinir senha',
                'error_id' => uniqid('reset_'),
            ], 500);
        }
    }

    /**
     * GET /api/email-verification-status
     *
     * Return whether the authenticated user's email is verified, the address,
     * and whether a re-send is allowed.
     *
     * Middleware: api.auth:api (no verified)
     * Auth: required (JWT)
     * Owner check: no (operates on the authenticated user's own record)
     *
     * Response shape: { success, email_verified, email, can_resend, last_sent } (200)
     *                 { success: false, message } on server error (500)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkEmailVerificationStatus()
    {
        try {
            $user = auth()->user();

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuário não autenticado',
                ], 401);
            }

            return response()->json([
                'success' => true,
                'email_verified' => $user->hasVerifiedEmail(),
                'email' => $user->email,
                'can_resend' => ! $user->hasVerifiedEmail() && $user->canResendVerificationEmail(),
                'last_sent' => $user->email_verification_sent_at?->toISOString(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao verificar status',
            ], 500);
        }
    }
}
