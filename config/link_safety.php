<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local lexical / brand heuristic (Layer 1)
    |--------------------------------------------------------------------------
    |
    | A free, network-less pre-check that runs inside LinkSafetyService::checkUrl
    | BEFORE the Google Safe Browsing call. It exists to catch obviously abusive
    | destinations that Safe Browsing misses — freshly-created phishing pages on
    | shared hosts (*.vercel.app, *.netlify.app, ...) whose hostname alone is a
    | dead giveaway (e.g. instagram-passwords-leaks.vercel.app).
    |
    | Both rules match against the URL *host* only (not the path) to keep the
    | false-positive rate low. Every block is logged as safety.url_blocked_heuristic
    | so the lists below can be tuned from production signal.
    |
    */

    // Master kill switch. Set LINK_SAFETY_HEURISTIC_ENABLED=false to disable the
    // whole Layer 1 heuristic without a code change (falls back to Safe Browsing).
    'heuristic_enabled' => env('LINK_SAFETY_HEURISTIC_ENABLED', true),

    /*
    | Rule A — compound keyword denylist.
    |
    | High-signal substrings that are almost never present in a legitimate host.
    | Kept deliberately hyphenated/compound so a legitimate single word ("password"
    | in 1password.com, "free" in freecodecamp.org) does NOT trigger a block.
    | Matched case-insensitively as a substring of the host.
    */
    'blocked_keywords' => [
        'passwords-leak',
        'password-leak',
        'senha-vaza',
        'senhas-vaza',
        'leaked-account',
        'account-leak',
        'verify-account',
        'account-verify',
        'recover-account',
        'free-followers',
        'seguidores-gratis',
        'free-robux',
        'free-vbucks',
        'free-giftcard',
        'pix-premiado',
        'premio-',
        'sorteio-',
        'seed-phrase',
        'wallet-connect-',
        'metamask-',
        'airdrop-',
    ],

    /*
    | Rule B — brand impersonation.
    |
    | Map of brand token => list of that brand's official registrable domains.
    | If the host contains the token but is neither the official domain nor a
    | subdomain of it, the URL is flagged. Example: a host containing "instagram"
    | that is not instagram.com / *.instagram.com is treated as impersonation.
    |
    | Bias: Brazilian audience — banks, marketplaces and the globally-phished
    | consumer brands. Extend from production logs.
    */
    'brands' => [
        'instagram' => ['instagram.com'],
        'whatsapp' => ['whatsapp.com', 'wa.me'],
        'facebook' => ['facebook.com', 'fb.com', 'fb.me'],
        'tiktok' => ['tiktok.com'],
        'netflix' => ['netflix.com'],
        'shopee' => ['shopee.com.br', 'shopee.com'],
        'mercadolivre' => ['mercadolivre.com.br'],
        'mercadopago' => ['mercadopago.com.br', 'mercadopago.com'],
        'nubank' => ['nubank.com.br'],
        'itau' => ['itau.com.br'],
        'bradesco' => ['bradesco.com.br'],
        'santander' => ['santander.com.br'],
        'caixa' => ['caixa.gov.br'],
        'correios' => ['correios.com.br'],
        'serasa' => ['serasa.com.br'],
        'steam' => ['steampowered.com', 'steamcommunity.com'],
    ],

    /*
    | Hosts that bypass the heuristic entirely (exact host or a subdomain of the
    | entry). Escape hatch for false positives found in production.
    */
    'allowed_hosts' => [],

    /*
    | Rule C — encurtadores encadeados.
    |
    | Quando o destino é um destes hosts, a verificação resolve a cadeia de
    | redirects e checa TAMBÉM a URL final. Sem isso, as duas camadas analisam o
    | encurtador intermediário — que é sempre limpo — e nunca o destino real.
    |
    | Por que uma lista e não "resolver sempre": resolver toda URL enviada
    | transformaria a criação de link (endpoint público, sem autenticação) num
    | buscador de URL arbitrária, ou seja uma superfície de SSRF nova, e somaria
    | latência ao caminho do usuário em 100% dos casos para cobrir uma fração.
    | Limitação aceita, igual à da lista de marcas: um encurtador fora da lista
    | ainda passa. Estender a partir de sinais de produção.
    |
    | Casa host exato ou subdomínio. Inclui os nossos próprios domínios porque já
    | existem cadeias internas em produção (thyqic → ochncy → e2vkdm → usqacg).
    */
    'shortener_hosts' => [
        'bit.ly',
        'tinyurl.com',
        't.co',
        'goo.gl',
        'ow.ly',
        'is.gd',
        'buff.ly',
        'rebrand.ly',
        'cutt.ly',
        'encurtador.com.br',
        's.gy',
        'shre.ink',
        '5664.in',
        'shorturl.at',
        'linktr.ee',
        'linkcharts.com.br',
    ],

];
