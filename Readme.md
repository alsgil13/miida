# MIIDA - Middleware de Ingestao, Integracao e Desacoplamento de Arquiteturas

O MIIDA e um middleware de alto desempenho desenvolvido em PHP CLI para atuar como motor de consistencia eventual e sincronizacao diferencial incremental de dados. O sistema foi projetado especificamente para solucionar os desafios de transicao arquitetural e a adocao do padrao CQRS (Command Query Responsibility Segregation), desacoplando um no de escrita legado de um no moderno de leitura de alta performance de forma totalmente agnostica a banco de dados.

---

## Arquitetura e Diferenciais Tecnicos

* Abordagem Nao-Intrusiva: O ecossistema opera sem instalar gatilhos (triggers), criar tabelas de log temporarias ou alterar qualquer estrutura no banco de dados de producao de origem.
* Padrao Strategy para Multi-SGBD: Toda a geracao de DDL (Data Definition Language) e DML (Data Manipulation Language) foi isolada por meio de contratos de interface. Isso permite que o middleware opere nativamente cruzando dados entre SQL Server, MySQL e PostgreSQL de forma simultanea e transparente.
* Camada Anticorrupcao (ACL) Declarativa: Mapeia, traduz nomes de colunas e efetua a coerco estrita de tipos (Type Casting) baseando-se em metadados JSON, blindando o novo banco de dados contra codificacoes antigas ou corrompidas, padronizando os dados em UTF-8.
* Idempotencia Baseada em Hash de Versao: Cada registro processado possui uma assinatura digital MD5 calculada em tempo de execucao. Se os dados de origem nao mudaram, o motor ignora a escrita, reduzindo o consumo de banda de rede e processamento de I/O de disco.
* Suporte Nativo a Chaves Primarias Compostas: O motor e o provisionador tratam relacionamentos complexos e chaves multiplas de forma dinamica, viabilizando inclusive a deteccao e exclusao automatica de registros orfaos.
* Consistencia e Auditoria: O estado temporal de sincronizacao de cada tabela e armazenado de forma centralizada no destino pelo ControlRepository, suportando quedas de conectividade e reinicializacoes de ambientes sem duplicar ou perder registros.

---

## Estrutura de Pastas do Projeto

miida/
- .env (Variaveis de ambiente para credenciais de acesso)
- autoload.php (Autoloader nativo padrao PSR-4 e injetor de .env)
- run.php (Launcher unificado do ecossistema completo)
- 1_inicializar.php (Script CLI de provisionamento e carga estrutural inicial)
- 2_sincronizar.php (Script CLI para execucao unica do pipeline)
- 3_orquestrador.php (Daemon principal em loop continuo)
- 4_limpeza_orfaos.php (Script CLI auxiliar para auditoria e expurgo manual)
- config/
  - pipeline_config.json (Centralizacao declarativa da infra e mapeamento de tabelas)
- examples/
  - exemplo_origem_mysql.sql (Script de teste para simular legado em MySQL)
  - exemplo_origem_postgres.sql (Script de teste para simular legado em PostgreSQL)
  - exemplo_origem_sqlserver.sql (Script de teste para simular legado em SQL Server)
  - pipeline_config.json (Manifesto de exemplo estruturado para testes)
- logs/
  - miida.log (Arquivo fisico de telemetria e logs de infraestrutura)
- src/
  - Database/
    - ConnectionFactory.php (Fabrica de conexoes PDO agnosticas)
    - ControlRepository.php (Guardiao relacional do estado temporal das tabelas)
    - Syntax/
      - SgbdSyntaxInterface.php (Contrato de metodos para dialetos de SGBD)
      - MySqlSyntax.php (Implementacao de sintaxe do motor MySQL)
      - PostgresSyntax.php (Implementacao de sintaxe do motor PostgreSQL)
      - SqlServerSyntax.php (Implementacao de sintaxe do motor SQL Server)
  - Engine/
    - DataSyncProcessor.php (Core Engine: Orquestrador de Extracao, ACL e Upsert)
    - LimpezaOrfaosProcessor.php (Engine de processamento e expurgo de dados deletados)
    - SchemaCloner.php (Provisionador idempotente de bancos e tabelas)
  - Services/
    - AntiCorruptionLayer.php (Higienizador de strings, tipos e gerador de hashes)
    - Logger.php (Subsistema hibrido de log)

---

## Como o MIIDA Funciona

O ciclo de sincronizacao do MIIDA baseia-se em tres etapas automaticas parametrizadas pelo arquivo pipeline_config.json:

1. Provisionamento Idempotente (Schema Cloner): O sistema varre as tabelas listadas no manifesto e gera automaticamente as tabelas de controle interno e as tabelas de negocio no banco moderno de destino com as novas tipagens e nomes definidos. Se as tabelas ja existirem, a estrutura e mantida intacta.
2. Carga Incremental Diferencial (Data Sync): O motor realiza uma consulta no banco de origem buscando apenas os registros cuja coluna de timestamp (como dt_alteracao ou dt_cadastro) seja maior do que a ultima data de sincronizacao registrada no ControlRepository. Cada linha passa pela Camada Anticorrupcao (ACL), que valida o tipo de dado e gera um hash MD5. O registro e inserido ou atualizado no destino.
3. Auditoria de Exclusoes (Limpeza de Orfaos): Periodicamente ou via comando CLI, o sistema executa um cruzamento rapido de chaves primarias (simples ou compostas) convertidas em strings de hash entre a origem e o destino. Registros que foram deletados fisicamente no sistema antigo sao identificados como orfaos e removidos do banco moderno em blocos controlados para evitar locks.

---

## Guia Passo a Passo para Testes

Siga as instrucoes abaixo para configurar um ambiente local de testes do MIIDA simulando uma migracao entre dois bancos de dados distintos.

### Pre-requisitos
* PHP 8.1 ou superior instalado em linha de comando.
* Extensoes do PDO habilitadas no seu php.ini de acordo com os bancos utilizados (pdo_mysql, pdo_pgsql ou pdo_sqlsrv).
* Acesso a dois bancos de dados locais ou em containers (um para simular o legado de Origem e outro para o destino Moderno).

### Passo 1: Preparar os arquivos de configuracao
1. Crie um arquivo chamado .env na raiz do projeto com as credenciais de acesso aos seus servidores de banco de dados, seguindo o modelo abaixo:
DB_ORIGEM_HOST=127.0.0.1
DB_ORIGEM_USER=root
DB_ORIGEM_PASSWORD=suasenha
DB_DESTINO_HOST=127.0.0.1
DB_DESTINO_USER=postgres
DB_DESTINO_PASSWORD=suasenha

2. Copie o arquivo manifesto de exemplo que esta em examples/pipeline_config.json para a pasta /config da raiz do projeto, renomeando-o para pipeline_config.json.
3. Abra o /config/pipeline_config.json e certifique-se de que os campos sgbd dentro de origem_command e destino_query correspondam exatamente aos motores de banco que voce utilizara nos testes (valores aceitos: sqlserver, mysql ou postgres).

### Passo 2: Popular o Banco de Dados de Origem
1. Abra a ferramenta de gerenciamento de banco de dados de sua preferencia (DBeaver, pgAdmin, SSMS).
2. Va ate a pasta examples/ do projeto e execute o script SQL correspondente ao banco que voce definiu como Origem (por exemplo, execute o exemplo_origem_mysql.sql se a sua origem for MySQL).
3. Este script criara a database erp_legado_db, criara as tabelas tb_usuarios e tb_vendas_itens (com chave composta) e inserira registros de teste contendo strings com espacos propositais em excesso.

### Passo 3: Executar a Inicializacao do Ecossistema
No terminal, execute o seguinte comando a partir da raiz do projeto:
php 1_inicializar.php

O MIIDA ira ler o manifesto JSON, conectar-se ao banco moderno de destino e criar de forma automatica as tabelas de controle de sincronizacao, a tabela de logs do sistema e as tabelas de negocio (usuarios e vendas_itens) com os novos nomes de colunas e padroes de tipos ja convertidos.

### Passo 4: Executar a Sincronizacao Inicial (One-shot)
No terminal, execute o comando de sincronizacao pontual:
php 2_sincronizar.php

O sistema extraira os dados do banco legado, passara os registros pela Camada Anticorrupcao (higienizando os espacos extras das strings e convertendo os tipos), gerara os hashes MD5 e salvara os dados no destino. Se voce consultar o seu banco moderno de destino, os dados ja estarao la totalmente normalizados.

### Passo 5: Testar o Modo Continuo (Daemon) e Limpeza de Orfaos
Para ver o middleware funcionando em tempo real como um servico de segundo plano:
php 3_orquestrador.php

O processo ficara em execucao continua no terminal monitorando novas alteracoes. 
1. Va ate o seu banco de origem e insira um novo registro ou atualize um campo na tabela tb_usuarios. Em ate 5 segundos o terminal do orquestrador exibira o processamento da alteracao detectada.
2. Delete um registro qualquer na tabela tb_vendas_itens no banco de origem. No proximo ciclo programado de limpeza ou ao rodar manualmente o comando php 4_limpeza_orfaos.php em outro terminal, o middleware detectara que a chave composta nao existe mais na origem e realizara o expurgo do registro correspondente no banco moderno.

### Execucao Unificada
Apos compreender o fluxo de cada script, voce pode iniciar todo o ecossistema sequencialmente em um unico comando utilizando o inicializador unificado:
php run.php