Olá, {{ $user_name }}!

{{ count($link_labels) === 1 ? 'Um link seu completou duas semanas sem cliques:' : 'Alguns links seus completaram duas semanas sem cliques:' }}
@foreach ($link_labels as $label)
- {{ $label }}
@endforeach

Link sem cliques quase sempre é link que ninguém viu. Três lugares que costumam
destravar isso em minutos:

1. Status do WhatsApp. Publique o link no seu status com uma frase curta dizendo
   o que a pessoa ganha ao clicar.

2. Link na bio do Instagram. Troque o link da bio pelo seu e chame para clicar
   nos stories do mesmo dia.

3. Grupos e comunidades do seu nicho. Compartilhe onde o assunto já é discutido —
   dois ou três grupos certos rendem mais que uma postagem genérica.

Ver meus links: {{ $links_url }}

--
Você recebe este aviso porque tem links ativos no Link Charts — é enviado uma única vez por link.
Para não receber mais: {{ $unsubscribe_url }}
