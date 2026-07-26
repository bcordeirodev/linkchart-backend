<?php

namespace App\Support;

use App\Logging\AppLogger;
use Illuminate\Http\Request;

/**
 * Única fonte de verdade para o IP do cliente.
 *
 * Delega em `$request->ip()`, que é autoritativo porque a procedência é validada
 * na borda: o Nginx do host aplica `real_ip_header CF-Connecting-IP` restrito às
 * faixas da Cloudflare, e o TrustProxies confia apenas na bridge do Docker. O
 * Symfony então caminha o X-Forwarded-For da direita para a esquerda e descarta os
 * saltos confiáveis, ignorando qualquer token que o cliente tenha forjado.
 *
 * Isto substituiu uma cadeia de precedência própria (query param → X-Real-IP →
 * primeiro token do X-Forwarded-For → CF-Connecting-IP) cujo terceiro passo era
 * controlado pelo cliente: um `X-Forwarded-For` forjado gravava o IP e o geo do
 * clique.
 *
 * @see docs/superpowers/specs/2026-07-25-ip-resolution-anti-abuse-design.md
 */
class ClientIpResolver
{
    /**
     * Resolve o IP real do cliente para a request atual.
     *
     * Fora de produção um override `?real_ip=` é honrado, para o desenvolvimento
     * local conseguir simular origens arbitrárias.
     *
     * @param  Request  $request  A request atual.
     * @return string O IP do cliente; nunca vazio.
     */
    public function fromRequest(Request $request): string
    {
        if (! $this->isProduction()) {
            $override = $request->query('real_ip');

            if (is_string($override) && filter_var($override, FILTER_VALIDATE_IP)) {
                return $override;
            }
        }

        $ip = $request->ip() ?: '127.0.0.1';

        if ($this->isProduction()) {
            $this->warnIfEdgeChainLooksBroken($request, $ip);
        }

        return $ip;
    }

    /**
     * Verifica se o IP é bem-formado e publicamente roteável.
     *
     * Usado para detectar configuração errada da cadeia de proxies: em produção, um
     * IP privado resolvido significa que a borda não está entregando o IP do cliente.
     *
     * @param  string  $ip  O endereço a validar.
     * @return bool True quando é um IP público válido.
     */
    public function isPublicIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Avisa quando a cadeia de proxy da borda parece não estar entregando o IP do
     * cliente. Dois sintomas distintos, e o segundo é o que importa:
     *
     *  - IP privado resolvido: TrustProxies ou o Nginx interno do container estão
     *    errados.
     *  - IP resolvido diferente do CF-Connecting-IP: o `real_ip_header` da borda não
     *    está sendo aplicado, ou seja estamos gravando o IP da **borda da
     *    Cloudflare** no lugar do cliente. Este é o modo de falha SILENCIOSO — o IP
     *    da borda é público, então a checagem de IP privado não o pega. Comparar
     *    contra o header dispensa manter uma cópia das faixas da CF aqui: quando a
     *    borda está correta, os dois valores são idênticos por construção.
     *
     * @param  Request  $request  A request atual.
     * @param  string  $ip  O IP já resolvido.
     */
    private function warnIfEdgeChainLooksBroken(Request $request, string $ip): void
    {
        if (! $this->isPublicIp($ip)) {
            AppLogger::event('app', 'warning', 'ip.private_resolved_in_production', [
                'ip' => $ip,
                'hint' => 'cadeia de proxy confiavel mal configurada: checar TrustProxies e o nginx do container',
            ]);

            return;
        }

        $cfConnectingIp = $request->header('CF-Connecting-IP');

        if (is_string($cfConnectingIp) && $cfConnectingIp !== '' && $cfConnectingIp !== $ip) {
            AppLogger::event('app', 'warning', 'ip.edge_chain_mismatch', [
                'resolved_ip' => $ip,
                'cf_connecting_ip' => $cfConnectingIp,
                'hint' => 'real_ip_header nao aplicado na borda: provavel gravacao do IP da cloudflare',
            ]);
        }
    }

    /**
     * Indica se a aplicação está rodando em produção.
     *
     * @return bool True em produção.
     */
    private function isProduction(): bool
    {
        return config('app.env') === 'production';
    }
}
