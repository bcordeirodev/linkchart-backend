<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
// Imports relacionados ao email removidos
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

    // Funcionalidades de recuperação de senha removidas
    // TODO: Implementar sistema de email quando necessário
}
