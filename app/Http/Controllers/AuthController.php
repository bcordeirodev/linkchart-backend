<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

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
     * Retorna os dados do usuário autenticado.
     */
    public function me()
    {
        return response()->json(auth()->user());
    }

    /**
     * Realiza o logout e invalida o token.
     */
    public function logout()
    {
        auth()->logout();

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
            'password'              => 'required|string|min:6|confirmed', // O campo "password_confirmation" é esperado
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
}
