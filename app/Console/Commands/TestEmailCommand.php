<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Models\User;

class TestEmailCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:test {email} {--user-id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testar envio de e-mail de recuperação de senha';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $userId = $this->option('user-id');

        $this->info("🧪 Testando envio de e-mail para: {$email}");

        try {
            // Buscar usuário
            if ($userId) {
                $user = User::find($userId);
            } else {
                $user = User::where('email', $email)->first();
            }

            if (!$user) {
                $this->error("❌ Usuário não encontrado para o e-mail: {$email}");
                return 1;
            }

            $this->info("👤 Usuário encontrado: {$user->name} (ID: {$user->id})");

            // Dados do e-mail
            $resetUrl = config('app.frontend_url') . '/reset-password?token=TEST_TOKEN_123&email=' . urlencode($email);

            $emailData = [
                'user' => $user,
                'resetUrl' => $resetUrl,
                'token' => 'TEST_TOKEN_123'
            ];

            $this->info("📧 Enviando e-mail de teste...");

            // Enviar e-mail
            Mail::send(['html' => 'emails.password-reset', 'text' => 'emails.password-reset-text'], $emailData, function ($message) use ($email, $user) {
                $message->to($email, $user->name)
                        ->subject('🧪 TESTE - Recuperação de Senha - ' . config('app.name'))
                        ->from(config('mail.from.address'), config('mail.from.name'));
            });

            $this->info("✅ E-mail de teste enviado com sucesso!");
            $this->info("🔗 URL de reset (teste): {$resetUrl}");

            // Mostrar configurações de e-mail
            $this->newLine();
            $this->info("📋 Configurações de E-mail:");
            $this->line("   Mailer: " . config('mail.default'));
            $this->line("   Host: " . config('mail.mailers.smtp.host'));
            $this->line("   Port: " . config('mail.mailers.smtp.port'));
            $this->line("   Username: " . config('mail.mailers.smtp.username'));
            $this->line("   Encryption: " . config('mail.mailers.smtp.encryption'));
            $this->line("   From: " . config('mail.from.address') . " (" . config('mail.from.name') . ")");

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Erro ao enviar e-mail: " . $e->getMessage());
            $this->error("📁 Arquivo: " . $e->getFile());
            $this->error("📍 Linha: " . $e->getLine());

            if ($this->option('verbose')) {
                $this->error("🔍 Stack trace:");
                $this->error($e->getTraceAsString());
            }

            return 1;
        }
    }
}
