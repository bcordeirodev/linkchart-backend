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
            // Testar tanto SMTP quanto SendGrid API
            $smtpConfig = $this->emailService->getMailConfiguration();
            $smtpConnectionTest = $this->emailService->testConnection();
            
            $sendGridConfig = $this->emailService->getSendGridConfiguration();
            $sendGridTest = $this->emailService->testSendGridAPI();

            return response()->json([
                'success' => true,
                'message' => 'Configuração de email verificada',
                'data' => [
                    'smtp_configuration' => $smtpConfig,
                    'smtp_connection_test' => $smtpConnectionTest,
                    'sendgrid_configuration' => $sendGridConfig,
                    'sendgrid_api_test' => $sendGridTest,
                    'recommended_method' => $sendGridTest['success'] ? 'SendGrid API' : 'SMTP',
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
            // Tentar primeiro SendGrid API, depois SMTP como fallback
            $sendGridResult = $this->emailService->sendTestEmailViaSendGridAPI(
                $request->email,
                $request->name
            );

            if ($sendGridResult['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $sendGridResult['message'],
                    'data' => [
                        'to' => $sendGridResult['to'] ?? null,
                        'method' => $sendGridResult['method'] ?? 'SendGrid API',
                        'status_code' => $sendGridResult['status_code'] ?? null,
                        'timestamp' => now()->toISOString()
                    ]
                ]);
            }

            // Fallback para SMTP se SendGrid API falhar
            $smtpResult = $this->emailService->sendTestEmail(
                $request->email,
                $request->name
            );

            return response()->json([
                'success' => $smtpResult['success'],
                'message' => $smtpResult['message'] . ' (Fallback SMTP)',
                'data' => [
                    'to' => $smtpResult['to'] ?? null,
                    'method' => 'SMTP (Fallback)',
                    'sendgrid_error' => $sendGridResult['message'],
                    'timestamp' => now()->toISOString()
                ]
            ], $smtpResult['success'] ? 200 : 500);

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

    /**
     * Enviar email de teste usando SendGrid API especificamente
     */
    public function sendTestViaSendGridAPI(Request $request): JsonResponse
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
            $result = $this->emailService->sendTestEmailViaSendGridAPI(
                $request->email,
                $request->name
            );

            return response()->json([
                'success' => $result['success'],
                'message' => $result['message'],
                'data' => [
                    'to' => $result['to'] ?? null,
                    'method' => $result['method'] ?? 'SendGrid API',
                    'status_code' => $result['status_code'] ?? null,
                    'timestamp' => now()->toISOString()
                ]
            ], $result['success'] ? 200 : 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro interno ao enviar email via SendGrid API',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
