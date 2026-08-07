@php
    // Montado aqui em vez de com @if inline: uma diretiva Blade colada a uma
    // palavra (`semana@if`) não compila — o `@` fica em fronteira de palavra e
    // o regex do Blade a ignora, deixando `@endif` vazar como texto no e-mail.
    $topLine = ($top_link_clicks === 1 ? '1 clique' : $top_link_clicks.' cliques').' esta semana';

    if ($top_link_lifetime > $top_link_clicks) {
        $topLine .= ', '.number_format($top_link_lifetime, 0, ',', '.').' no total';
    }
@endphp
Olá, {{ $user_name }}!

Resumo dos seus links de {{ $period_label }}:

Total: {{ $clicks_label }} na última semana
@if ($first_week)
🎉 Primeira semana com cliques!
@elseif ($variation_label !== null)
Variação: {{ $variation_label }} vs semana anterior
@endif
@if ($top_link_label !== null)
Top link da semana: {{ $top_link_label }} ({{ $topLine }})
@endif
@foreach ($facts as $fact)
{{ $loop->first ? PHP_EOL : '' }}- {{ $fact['label'] }}
@endforeach

{{-- URLs vão sem escape: `{{ }}` converteria o `&` dos parâmetros UTM em
     `&amp;`, e num corpo text/plain isso não é decodificado por ninguém — o
     segundo parâmetro chegaria ao GA como `amp;utm_medium`. Não há superfície
     de injeção aqui: são URLs que este job monta. --}}
@if ($public_url)
Ver o histórico completo deste link: {!! $public_url !!}
Ou abra o painel completo: {!! $dashboard_url !!}
@else
Ver estatísticas completas: {!! $dashboard_url !!}
@endif

--
Você recebe este resumo semanal porque tem links ativos no Link Charts.
Para não receber mais: {!! $unsubscribe_url !!}
