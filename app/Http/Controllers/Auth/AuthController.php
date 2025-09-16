<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Mail;
use App\Services\EmailService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\User;

class AuthController extends Controller
{
    /**
     * Registrar um novo usuário
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
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $token = JWTAuth::fromUser($user);

            return response()->json([
                'success' => true,
                'message' => 'Usuário registrado com sucesso',
                'user' => $user,
                'token' => $token
            ], 201);

        } catch (\Exception $e) {
            \Log::channel('api_errors')->error('Registration Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao registrar usuário. Verifique os logs.',
                'error_id' => uniqid('reg_')
            ], 500);
        }
    }

    /**
     * Login do usuário
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
                    'errors' => $validator->errors()
                ], 422);
            }

            $credentials = $request->only('email', 'password');

            if (!$token = JWTAuth::attempt($credentials)) {
                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => 'Credenciais inválidas'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'token' => $token,
                'user' => auth()->user()
            ]);

        } catch (\Exception $e) {
            \Log::channel('api_errors')->error('Login Error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao fazer login. Verifique os logs.',
                'error_id' => uniqid('login_')
            ], 500);
        }
    }

    /**
     * Logout do usuário
     */
    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => 'Logout realizado com sucesso'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao fazer logout'
            ], 500);
        }
    }

    /**
     * Obter informações do usuário autenticado
     */
    public function me()
    {
        try {
            return response()->json([
                'success' => true,
                'user' => auth()->user()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao obter informações do usuário'
            ], 500);
        }
    }

    /**
     * Refresh do token
     */
    public function refresh()
    {
        try {
            $token = JWTAuth::refresh(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'token' => $token
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao renovar token'
            ], 500);
        }
    }

    /**
     * Atualizar perfil do usuário
     */
    public function updateProfile(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email,' . auth()->id(),
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation Error',
                    'message' => 'Dados inválidos fornecidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth()->user();
            $user->update($request->only(['name', 'email']));

            return response()->json([
                'success' => true,
                'message' => 'Perfil atualizado com sucesso',
                'user' => $user->fresh() // Retorna dados atualizados
            ]);

        } catch (\Exception $e) {
            \Log::channel('api_errors')->error('Update Profile Error', [
                'user_id' => auth()->id(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao atualizar perfil. Verifique os logs.',
                'error_id' => uniqid('profile_')
            ], 500);
        }
    }

    /**
     * Alterar senha do usuário
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
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = auth()->user();

            // Verificar senha atual
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'error' => 'Invalid Password',
                    'message' => 'Senha atual incorreta'
                ], 422);
            }

            // Atualizar senha
            $user->update([
                'password' => Hash::make($request->new_password)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Senha alterada com sucesso'
            ]);

        } catch (\Exception $e) {
            \Log::channel('api_errors')->error('Change Password Error', [
                'user_id' => auth()->id(),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao alterar senha. Verifique os logs.',
                'error_id' => uniqid('pwd_')
            ], 500);
        }
    }

    /**
     * Enviar link de recuperação de senha por e-mail
     */
    public function sendPasswordResetLink(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation Error',
                    'message' => 'E-mail inválido ou não encontrado',
                    'errors' => $validator->errors()
                ], 422);
            }

            $email = $request->email;
            $user = User::where('email', $email)->first();

            if (!$user) {
                return response()->json([
                    'error' => 'User Not Found',
                    'message' => 'Usuário não encontrado'
                ], 404);
            }

            // Gerar token único
            $token = Str::random(64);

            // Salvar token na tabela password_reset_tokens
            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                [
                    'email' => $email,
                    'token' => Hash::make($token),
                    'created_at' => Carbon::now()
                ]
            );

            // Enviar e-mail usando PHPMailer
            $resetUrl = config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($email);

            $emailService = new EmailService();
            $emailResult = $emailService->sendPasswordResetEmail($email, $user->name, $resetUrl, $token);

            if (!$emailResult['success']) {
                return response()->json([
                    'error' => 'Email Error',
                    'message' => 'Erro ao enviar email de recuperação: ' . $emailResult['message']
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Link de recuperação enviado para seu e-mail'
            ]);

        } catch (\Exception $e) {
            \Log::channel('api_errors')->error('Password Reset Link Error', [
                'email' => $request->email ?? 'unknown',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao enviar link de recuperação. Verifique os logs.',
                'error_id' => uniqid('reset_')
            ], 500);
        }
    }

    /**
     * Redefinir senha usando token
     */
    public function resetPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
                'token' => 'required|string',
                'password' => 'required|string|min:6|confirmed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation Error',
                    'message' => 'Dados inválidos fornecidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $email = $request->email;
            $token = $request->token;
            $password = $request->password;

            // Buscar token na tabela
            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->first();

            if (!$passwordReset) {
                return response()->json([
                    'error' => 'Invalid Token',
                    'message' => 'Token de recuperação inválido ou expirado'
                ], 422);
            }

            // Verificar se token não expirou (24 horas)
            $tokenAge = Carbon::parse($passwordReset->created_at);
            if ($tokenAge->diffInHours(Carbon::now()) > 24) {
                // Remover token expirado
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                return response()->json([
                    'error' => 'Token Expired',
                    'message' => 'Token de recuperação expirado. Solicite um novo.'
                ], 422);
            }

            // Verificar token
            if (!Hash::check($token, $passwordReset->token)) {
                return response()->json([
                    'error' => 'Invalid Token',
                    'message' => 'Token de recuperação inválido'
                ], 422);
            }

            // Atualizar senha do usuário
            $user = User::where('email', $email)->first();
            $user->update([
                'password' => Hash::make($password)
            ]);

            // Remover token usado
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Senha redefinida com sucesso'
            ]);

        } catch (\Exception $e) {
            \Log::channel('api_errors')->error('Password Reset Error', [
                'email' => $request->email ?? 'unknown',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Server Error',
                'message' => 'Erro ao redefinir senha. Verifique os logs.',
                'error_id' => uniqid('reset_pwd_')
            ], 500);
        }
    }

    /**
     * Verificar se token de recuperação é válido
     */
    public function verifyResetToken(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'token' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation Error',
                    'message' => 'Dados inválidos fornecidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $email = $request->email;
            $token = $request->token;

            // Buscar token na tabela
            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $email)
                ->first();

            if (!$passwordReset) {
                return response()->json([
                    'valid' => false,
                    'message' => 'Token não encontrado'
                ]);
            }

            // Verificar se token não expirou (24 horas)
            $tokenAge = Carbon::parse($passwordReset->created_at);
            if ($tokenAge->diffInHours(Carbon::now()) > 24) {
                // Remover token expirado
                DB::table('password_reset_tokens')->where('email', $email)->delete();

                return response()->json([
                    'valid' => false,
                    'message' => 'Token expirado'
                ]);
            }

            // Verificar token
            if (!Hash::check($token, $passwordReset->token)) {
                return response()->json([
                    'valid' => false,
                    'message' => 'Token inválido'
                ]);
            }

            return response()->json([
                'valid' => true,
                'message' => 'Token válido'
            ]);

        } catch (\Exception $e) {
            \Log::channel('api_errors')->error('Token Verification Error', [
                'email' => $request->email ?? 'unknown',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'valid' => false,
                'message' => 'Erro ao verificar token'
            ], 500);
        }
    }
}
