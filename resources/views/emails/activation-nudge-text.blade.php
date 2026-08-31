Olá, {{ $user_name }}!

Falta só o primeiro link para o Link Charts começar a trabalhar por você.

É um passo só: cole a URL que você já compartilha e receba de volta um link
curto. A partir daí, cada clique vira informação — de onde a pessoa veio, em que
horário clicou e por qual rede chegou até você.
{{-- URLs vão sem escape: `{{ }}` converteria o `&` dos parâmetros UTM em
     `&amp;`, e num corpo text/plain isso não é decodificado por ninguém — o
     segundo parâmetro chegaria ao GA como `amp;utm_medium`. Não há superfície
     de injeção aqui: são URLs que este job monta. --}}
Criar meu primeiro link: {!! $create_url !!}

--
Você recebe este e-mail porque criou uma conta no Link Charts há poucos dias — ele é enviado uma única vez.
Para não receber mais: {!! $unsubscribe_url !!}
