<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;

class AuthController
{
    /**
     * Realiza o login e retorna o token JWT.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $guard = auth()->guard('api');

        if (!$token = $guard->attempt($credentials)) {
            return response()->json(['error' => 'Credenciais inválidas'], 401);
        }

        return response()->json([
            'token' => $token,
            'user'  => $guard->user()
        ]);
    }



    /**
     * Realiza o logout e invalida o token.
     */
    public function logout()
    {
        auth()->guard('api')->logout();

        return response()->json(['message' => 'Logout realizado com sucesso']);
    }

    /**
     * Registra um novo usuário e retorna o token JWT.
     */
    public function register(Request $request)
    {

        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'email'                 => 'required|email|unique:users,email',
            'password'              => 'required|string|min:6|confirmed',
        ]);


        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);


        $token = JWTAuth::fromUser($user);

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Login ou registro via Google.
     *
     * Recebe no body JSON:
     *   - email: string  (obrigatório)
     *   - name:  string  (obrigatório)
     */
    public function googleLogin(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'name'  => 'required|string|max:255',
        ]);

        // 1) Tenta encontrar pelo email
        $user = User::where('email', $validated['email'])->first();

        // 2) Se não existir, cria um novo com senha aleatória
        if (! $user) {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make(Str::random(16)),
            ]);
        }

        // 3) Gera um novo JWT para o usuário
        $token = JWTAuth::fromUser($user);

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    /**
     * Retorna informações do usuário autenticado.
     */
    public function me(Request $request)
    {
        $user = auth()->guard('api')->user();

        if (!$user) {
            return response()->json([
                'error' => 'Usuário não autenticado',
                'message' => 'Token JWT inválido ou expirado'
            ], 401);
        }

        return response()->json([
            'user' => $user
        ]);
    }

    /**
     * Atualiza informações do perfil do usuário.
     */
    public function updateProfile(Request $request)
    {
        $user = auth()->guard('api')->user();

        if (!$user) {
            return response()->json([
                'error' => 'Usuário não autenticado',
                'message' => 'Token JWT inválido ou expirado'
            ], 401);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
        ]);

        try {
            $user->update($validated);

            return response()->json([
                'message' => 'Perfil atualizado com sucesso',
                'user' => $user->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Erro ao atualizar perfil',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
