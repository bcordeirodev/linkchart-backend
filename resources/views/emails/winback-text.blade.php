Olá, {{ $user_name }}!

Seus links continuaram trabalhando enquanto você esteve fora:
{{ $total }} cliques nos últimos {{ $window_days }} dias.
@if ($top_link_label !== null)

Link que mais rendeu: {{ $top_link_label }} ({{ $top_link_clicks_label }} no período)
@endif

Cada um desses cliques deixou rastro: de onde a pessoa veio, em que horário e
por qual rede. É esse mapa que diz onde vale divulgar da próxima vez.
{{-- URLs vão sem escape: `{{ }}` converteria o `&` dos parâmetros UTM em
     `&amp;`, e num corpo text/plain isso não é decodificado por ninguém — o
     segundo parâmetro chegaria ao GA como `amp;utm_medium`. Não há superfície
     de injeção aqui: são URLs que este job monta. --}}
Ver estatísticas: {!! $stats_url !!}
Ver todos os links: {!! $links_url !!}

--
Você recebe este aviso porque tem links ativos no Link Charts — ele é enviado no máximo uma vez a cada {{ $cooldown_days }} dias.
Para não receber mais: {!! $unsubscribe_url !!}
