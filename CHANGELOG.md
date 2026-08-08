Versão 1.1.0 (07/08/2026)
* Recursos adicionados
  - Licenças de bundle: uma única chave passa a cobrir vários produtos (ex.: "Clube M"). Nada muda no protocolo para o produto — ele continua enviando o próprio `product_slug` e a mesma chave simplesmente valida para todos os produtos que o bundle concede. A resposta de validação traz o campo aditivo `bundle` (`id`, `name`, `slug`, `products[]`), acessível por `LicenseStatus::get( 'bundle' )`, permitindo exibir "Licenciado via Clube M" e listar o que está incluso
  - `LicenseStatus::extra()` / `::get()` expõem os campos que o endpoint de validação retorna além dos modelados pela classe (plano, URL de renovação, expiração do suporte, motivo da falha), para o produto renderizar a própria tela de licença sem uma segunda requisição. São persistidos junto com o status, então continuam disponíveis durante uma indisponibilidade no período de carência
* Alterações
  - `Manager::validate()` passa a armazenar a `message` retornada pelo servidor no status, em vez de descartá-la
  - `Manager::activate()` envia `product_slug` junto do `plugin_version` que o `Environment::request_meta()` já fornecia. A API exige os dois quando a chave é uma licença de bundle — seus produtos compartilham um único assento, então a requisição precisa dizer qual produto está ativando — e mantém os campos opcionais para licenças de produto único. Versões antigas da API ignoram o campo extra
  - `Manager::deactivate()` também envia `product_slug`. Em uma licença de bundle isso libera apenas o vínculo deste produto com o site — o assento permanece com os demais produtos até o último sair — de modo que desinstalar um plugin não desativa os outros. A API exige o campo para chaves de bundle. Consequência para os consumidores: uma cópia do SDK anterior a esta versão não consegue ativar nem desativar uma licença de bundle (recebe `400`); licenças de produto único não são afetadas

Versão 1.0.0 (18/06/2026)
* Recursos adicionados
  - Carregador com reconhecimento de versão (`mds-sdk.php`), que inicializa a cópia embarcada mais recente e evita colisão de classes entre plugins
  - Fachada `SDK::register()` e contêiner `Integration` por produto
  - Ciclo de vida da licença: ativar / desativar / validar por heartbeat, com período de carência configurável e armazenamento de opções compatível com multisite
  - Verificação de assinatura ed25519 das respostas (`SignatureVerifier`) — núcleo antipirataria, que rejeita respostas sem assinatura, adulteradas ou reenviadas nas chamadas críticas de licença
  - Atualizadores de plugin e de tema, com verificação de atualização limitada por transient e disparada pelo cron
  - Listagem de versões e rollback (downgrade), protegidos por capability e nonce
  - Heartbeat diário da licença via WP-Cron, com jitter por site
  - Interface administrativa reutilizável e sobrescrevível (painel de licença, lista de rollback, avisos)
  - Suíte de testes PHPUnit e CI para PHP 7.4–8.3
