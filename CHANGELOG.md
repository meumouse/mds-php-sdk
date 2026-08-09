Versão 1.2.0 (09/08/2026)
* Recursos adicionados
  - Sistema de feature flags (`Config\Features`): cada parte do SDK — licença, atualizações, rollback, heartbeat, avisos, modal de detalhes, menu automático, painéis administrativos e log — passa a ser um recurso nomeado que pode ser ligado ou desligado por produto. Sem nenhuma configuração o comportamento é idêntico ao da 1.1.0
  - Presets pela chave `mode` em `SDK::register()`: `full` (padrão, tudo ligado), `license_only` (apenas direito de uso — o produto distribui atualizações por outro canal) e `updates_only` (produto gratuito servido pelo MDS, sem chave e sem interface de licença)
  - Chave `features` na configuração, que sobrescreve o preset item a item — por exemplo `array( 'notices' => false )` para o produto renderizar os próprios avisos
  - Resolução em quatro camadas, nesta ordem: preset → `features` → filtros → constantes. As constantes `MDS_SDK_FEATURE_{NOME}` e `MDS_{SLUG}_FEATURE_{NOME}` são a palavra final e não podem ser revertidas por código de produto, servindo como interruptor do dono do site
  - Filtros de recursos: `mds_sdk_features` (todos os produtos, com o slug e a configuração como argumentos) e `mds_{slug}_feature_{nome}` (recurso a recurso)
  - Filtros de avisos: `mds_{slug}_notice_screens` (telas onde o aviso aparece), `mds_{slug}_notice_message` (texto), `mds_{slug}_notice_args` (tipo, dispensável, texto e URL do link) e `mds_{slug}_should_show_notice` (decisão final, capaz de forçar tanto a exibição quanto a supressão)
  - Demais filtros: `mds_{slug}_capability` (capability exigida por contexto: `settings`, `notices` ou `rollback`), `mds_{slug}_update_check_ttl`, `mds_{slug}_grace_period`, `mds_{slug}_heartbeat_recurrence`, `mds_{slug}_settings_url` (para quando o painel é embutido em uma tela própria) e `mds_{slug}_request_body` (payload de saída)
  - Ações `mds_{slug}_before_boot` (recebe `Features` e `Product`) e `mds_{slug}_booted` (recebe a `Integration`)
  - `LicenseStatus::STATUS_NOT_REQUIRED`, `LicenseStatus::not_required()` e `LicenseStatus::is_required()`, que descrevem um produto sem licenciamento sem precisar fingir um status inválido
  - Acessores novos: `Integration::features()`, `Integration::notices()`, `Product::features()`, `Product::capability()`, `Product::prefix()`, `License\Manager::is_enabled()`, `Cron\Scheduler::is_enabled()` e `Cron\Scheduler::recurrence()`
* Alterações
  - Atualizações deixam de exigir licença incondicionalmente. O gate agora é o recurso `license_gate_updates`, ligado por padrão; com ele desligado a verificação de atualização é enviada sem `license_key` (o campo é omitido, não enviado vazio, para a API distinguir "produto gratuito" de "chave ausente"). A resposta continua obrigatoriamente verificada por assinatura ed25519 — nenhum recurso e nenhum filtro afeta isso. **Requer suporte correspondente na API**: `/v2/update-check` precisa aceitar requisição sem `license_key` para produtos marcados como gratuitos, caso contrário o modo `updates_only` recebe erro do servidor
  - Recursos são registrados conforme o custo do próprio registro: os estruturais (`updates`, `heartbeat`, `admin_menu`, `license`, `rollback`) são decididos uma única vez no boot, porque registrá-los custa uma chamada de API ou agenda um evento no cron; os comportamentais (`notices`, `update_details`, painéis, `logging`) são reavaliados a cada uso e continuam filtráveis muito depois do boot, inclusive a partir do `functions.php` de um tema
  - Com `license` desligado o módulo inteiro fica inerte: nenhuma opção é lida ou gravada, nenhuma requisição é feita, `status()` retorna `not_required` e `is_active()` / `Integration::is_licensed()` retornam `true`, de modo que o código do consumidor que faz `if ( $sdk->is_licensed() )` continua liberando o produto
  - `Cron\Scheduler::register()` passa a remover um evento previamente agendado quando o heartbeat é desligado, em vez de deixá-lo órfão
  - `Cron\Scheduler::schedule()` usa a recorrência filtrada, com validação contra `wp_get_schedules()` e queda para `daily` quando o valor não corresponde a um agendamento registrado
  - Capabilities deixam de ser fixas no código: `manage_options` para licença e avisos, `update_plugins` / `update_themes` para rollback, todas passando pelo filtro `mds_{slug}_capability`
  - `Admin\LicenseSettings::render()` passou a exigir a capability antes de renderizar, e não apenas nos handlers de formulário
  - `Support\Logger` respeita o recurso `logging`, cujo padrão continua sendo `WP_DEBUG` — o comportamento anterior — mas que agora pode ser forçado para capturar uma requisição específica
  - `Admin\Notices` recebe a `LicenseSettings` em vez da URL já resolvida, para que `mds_{slug}_settings_url` funcione mesmo aplicado depois do boot
  - `Config\Product` guarda `update_check_ttl` e `grace_period` sem tratamento e aplica o filtro e o clamp na leitura, então ambos podem ser ajustados por site
  - Novo código de erro `mds_rollback_disabled`, retornado por `Rollback\Manager::rollback()` quando o recurso está desligado

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
