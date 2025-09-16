<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\EmailService;
use Exception;

class TestEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'email:test {email} {--name= : Nome do destinatário} {--send : Enviar email real}';

    /**
     * The console command description.
     */
    protected $description = 'Testa configuração de email e conectividade SMTP';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $name = $this->option('name') ?? 'Usuário Teste';
        $sendReal = $this->option('send');

        $this->info('🔍 Iniciando teste de email...');
        $this->newLine();

        // 1. Verificar configurações
        $this->testConfigurations();

        // 2. Testar conectividade
        $this->testConnectivity();

        // 3. Enviar email se solicitado
        if ($sendReal) {
            $this->sendTestEmail($email, $name);
        } else {
            $this->warn('⚠️  Para enviar email real, use a opção --send');
            $this->line('   Exemplo: php artisan email:test ' . $email . ' --send');
        }

        $this->newLine();
        $this->info('✅ Teste de email concluído!');
    }

    private function testConfigurations()
    {
        $this->info('📋 Verificando configurações de email...');

        $configs = [
            'APP_ENV' => config('app.env'),
            'MAIL_MAILER' => config('mail.default'),
            'MAIL_HOST' => config('mail.mailers.smtp.host'),
            'MAIL_PORT' => config('mail.mailers.smtp.port'),
            'MAIL_USERNAME' => config('mail.mailers.smtp.username'),
            'MAIL_PASSWORD' => config('mail.mailers.smtp.password') ? '✅ Configurado' : '❌ NÃO CONFIGURADO',
            'MAIL_FROM_ADDRESS' => config('mail.from.address'),
            'MAIL_FROM_NAME' => config('mail.from.name'),
        ];

        foreach ($configs as $key => $value) {
            $this->line("  {$key}: {$value}");
        }

        $this->newLine();
    }

    private function testConnectivity()
    {
        $this->info('🌐 Testando conectividade SMTP...');

        try {
            // Testar DNS
            $host = config('mail.mailers.smtp.host');
            $ip = gethostbyname($host);

            if ($ip !== $host) {
                $this->line("  ✅ DNS {$host}: {$ip}");
            } else {
                $this->error("  ❌ DNS {$host}: Falha na resolução");
                return;
            }

            // Testar conexão SMTP
            $emailService = new EmailService();
            $result = $emailService->testConnection();

            if ($result['success']) {
                $this->info('  ✅ Conexão SMTP: Sucesso');
            } else {
                $this->error('  ❌ Conexão SMTP: ' . $result['message']);
            }

        } catch (Exception $e) {
            $this->error('  ❌ Erro de conectividade: ' . $e->getMessage());
        }

        $this->newLine();
    }

    private function sendTestEmail($email, $name)
    {
        $this->info('📧 Enviando email de teste...');

        try {
            $emailService = new EmailService();
            $result = $emailService->sendTestEmail($email, $name);

            if ($result['success']) {
                $this->info('  ✅ Email enviado com sucesso!');
                $this->line('  📬 Destinatário: ' . $email);
                $this->line('  👤 Nome: ' . $name);
                $this->line('  🏷️  Assunto: Teste de Email - Link Charts');
                $this->line('  📅 Data/Hora: ' . date('d/m/Y H:i:s'));
            } else {
                $this->error('  ❌ Falha no envio!');
                $this->line('  🚨 Erro: ' . $result['message']);

                if (isset($result['error'])) {
                    $this->line('  📝 Detalhes: ' . $result['error']);
                }
            }

        } catch (Exception $e) {
            $this->error('  ❌ Exceção: ' . $e->getMessage());
            $this->line('  📍 Arquivo: ' . $e->getFile() . ':' . $e->getLine());
        }

        $this->newLine();
    }
}
