Olá, {{ $user_name }}!

Resumo dos seus links de {{ $period_label }}:

Total: {{ $clicks_label }} na última semana
@if ($first_week)
🎉 Primeira semana com cliques!
@elseif ($variation_label !== null)
Variação: {{ $variation_label }} vs semana anterior
@endif
@if ($top_link_label !== null)
Top link da semana: {{ $top_link_label }} ({{ $top_link_clicks === 1 ? '1 clique' : $top_link_clicks.' cliques' }})
@endif

Ver estatísticas completas: {{ $stats_url }}

--
Você recebe este resumo semanal porque tem links ativos no Link Charts.
Para não receber mais: {{ $unsubscribe_url }}
