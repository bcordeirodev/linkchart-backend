<?php

namespace App\Http\Controllers;

use App\Services\EmailService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller;

class EmailTestController extends Controller
{
    private EmailService $emailService;

    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    /**
     * Testar configuração de email
     */
    public function testConfiguration(): JsonResponse
    {
        try {
            $config = $this->emailService->getMailConfiguration();
            $connectionTest = $this->emailService->testConnection();

            return response()->json([
                'success' => true,
                'message' => 'Configuração de email verificada',
                'data' => [
                    'configuration' => $config,
                    'connection_test' => $connectionTest,
                    'timestamp' => now()->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao verificar configuração de email',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enviar email de teste
     */
    public function sendTest(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'name' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->emailService->sendTestEmail(
                $request->email,
                $request->name
            );

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => [
                    'to' => $result['to'] ?? null,
                    'mailer' => $result['mailer'] ?? null,
                    'timestamp' => now()->toISOString()
                ]
            ], $result['success'] ? 200 : 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao enviar email de teste',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enviar email personalizado
     */
    public function sendCustom(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'subject' => 'required|string|max:255',
            'html_content' => 'required|string',
            'text_content' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->emailService->sendCustomEmail(
                $request->email,
                $request->subject,
                $request->html_content,
                $request->text_content
            );

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => [
                    'to' => $result['to'] ?? null,
                    'timestamp' => now()->toISOString()
                ]
            ], $result['success'] ? 200 : 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao enviar email personalizado',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
