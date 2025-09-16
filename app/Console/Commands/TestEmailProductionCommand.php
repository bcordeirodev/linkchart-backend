<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EmailService;
use Illuminate\Support\Facades\Log;
use Exception;

class TestEmailProductionCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'email:test-production {email} {--send : Enviar email real}';

    /**
     * The console command description.
     */
    protected $description = 'Testa configurações de email em produção';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $sendReal = $this->option('send');

        $this->info('🔍 Testando configurações de email em produção...');
        $this->newLine();

        // 1. Verificar configurações
        $this->testConfigurations();

        // 2. Testar dependências
        $this->testDependencies();

        // 3. Testar conectividade DNS
        $this->testDNS();

        // 4. Enviar email se solicitado
        if ($sendReal) {
            $this->sendTestEmail($email);
        }

        $this->newLine();
        $this->info('✅ Teste concluído!');
    }

    private function testConfigurations()
    {
        $this->info('📋 Verificando configurações...');

        $configs = [
            'APP_ENV' => config('app.env'),
            'MAIL_MAILER' => config('mail.default'),
            'MAIL_HOST' => config('mail.mailers.smtp.host'),
            'MAIL_PORT' => config('mail.mailers.smtp.port'),
            'MAIL_ENCRYPTION' => config('mail.mailers.smtp.encryption'),
            'MAIL_USERNAME' => config('mail.mailers.smtp.username') ? '***configurado***' : 'NÃO CONFIGURADO',
            'MAIL_PASSWORD' => config('mail.mailers.smtp.password') ? '***configurado***' : 'NÃO CONFIGURADO',
        ];

        foreach ($configs as $key => $value) {
            $status = $value ? '✅' : '❌';
            $this->line("  {$status} {$key}: {$value}");
        }

        $this->newLine();
    }

    private function testDependencies()
    {
        $this->info('📦 Verificando dependências...');

        // Verificar PHPMailer
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $this->line('  ✅ PHPMailer: Instalado');
        } else {
            $this->line('  ❌ PHPMailer: NÃO ENCONTRADO');
        }

        // Verificar extensões PHP
        $extensions = ['openssl', 'mbstring', 'curl'];
        foreach ($extensions as $ext) {
            if (extension_loaded($ext)) {
                $this->line("  ✅ Extensão {$ext}: Carregada");
            } else {
                $this->line("  ❌ Extensão {$ext}: NÃO CARREGADA");
            }
        }

        $this->newLine();
    }

    private function testDNS()
    {
        $this->info('🌐 Testando conectividade DNS...');

        try {
            $host = config('mail.mailers.smtp.host');
            $ip = gethostbyname($host);
            
            if ($ip !== $host) {
                $this->line("  ✅ DNS {$host}: {$ip}");
            } else {
                $this->line("  ❌ DNS {$host}: Falha na resolução");
            }
        } catch (Exception $e) {
            $this->line("  ❌ DNS: Erro - " . $e->getMessage());
        }

        $this->newLine();
    }

    private function sendTestEmail($email)
    {
        $this->info('📧 Enviando email de teste...');

        try {
            $emailService = new EmailService();
            
            $result = $emailService->sendPasswordResetEmail(
                $email,
                'Usuário Teste Produção',
                'https://linkcharts.com.br/reset-password?token=teste-prod',
                'token-teste-producao-123'
            );

            if ($result['success']) {
                $this->info('  ✅ Email enviado com sucesso!');
                $this->line('  📬 Destinatário: ' . $email);
            } else {
                $this->error('  ❌ Falha no envio!');
                $this->line('  🚨 Erro: ' . $result['message']);
            }

        } catch (Exception $e) {
            $this->error('  ❌ Exceção: ' . $e->getMessage());
            $this->line('  📍 Arquivo: ' . $e->getFile() . ':' . $e->getLine());
        }

        $this->newLine();
    }
}
