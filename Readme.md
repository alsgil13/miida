# MIIDA - Middleware de Ingestao, Integracao e Desacoplamento de Arquiteturas

O MIIDA e um middleware desenvolvido em PHP CLI para atuar como motor de consistencia eventual e sincronizacao diferencial incremental de dados. O sistema foi projetado especificamente para solucionar os desafios de transicao arquitetural e a adocao do padrao CQRS (Command Query Responsibility Segregation), desacoplando um no de escrita e leitura legado de um no moderno de leitura de forma totalmente agnostica a banco de dados.

---

## Arquitetura e Diferenciais Tecnicos

* Abordagem Nao-Intrusiva: O ecossistema opera sem instalar gatilhos (triggers), criar tabelas de log temporarias ou alterar qualquer estrutura no banco de dados de producao de origem.
* Padrao Strategy para Multi-SGBD: Toda a geracao de DDL (Data Definition Language) e DML (Data Manipulation Language) foi isolada por meio de contratos de interface. Isso permite que o middleware opere nativamente cruzando dados entre SQL Server, MySQL e PostgreSQL de forma simultanea e transparente, além de abrir a possibilidade de implementacao de novos dialetos de SGBD no futuro.
* Camada Anticorrupcao (ACL) Declarativa: Mapeia, traduz nomes de colunas e efetua a correção estrita de tipos (Type Casting) baseando-se em metadados JSON, blindando o novo banco de dados contra codificacoes antigas ou corrompidas, padronizando os dados em UTF-8.
* Idempotencia Baseada em Hash de Versao: Cada registro processado possui uma assinatura digital MD5 calculada em tempo de execucao. Se os dados de origem nao mudaram, o motor ignora a escrita, reduzindo o consumo de banda de rede e processamento de I/O de disco.
* Suporte Nativo a Chaves Primarias Compostas: O motor e o provisionador tratam relacionamentos complexos e chaves multiplas de forma dinamica, viabilizando inclusive a deteccao e exclusao de registros apagados na origem.
* Consistencia e Auditoria: O estado temporal de sincronizacao de cada tabela e armazenado de forma centralizada no destino pelo ControlRepository, suportando quedas de conectividade e reinicializacoes de ambientes sem duplicar ou perder registros.

---

## Estrutura de Pastas do Projeto

miida/
- .env.example (Variaveis de ambiente para credenciais de acesso)
- autoload.php (Autoloader nativo padrao PSR-4 e injetor de .env)
- config/
  - pipeline_config.json (Centralizacao declarativa da infra e mapeamento de tabelas)
- logs/ 
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
    - DataSyncProcessor.php (Responsável pela carga dos dados e sincronizacao incremental)
    - LimpezaOrfaosProcessor.php (Engine de processamento e expurgo de dados deletados)
    - SchemaCloner.php (Provisionador de bancos e tabelas)
  - Pipeline/
    - FilterInterface.php (interface aplicada aos filtros [engine])
  - Services/
    - AntiCorruptionLayer.php (Higienizador de dados)
    - Logger.php (Subsistema de log)
  - utils/
    - data_handlers/ (SQL[DML] utilizados para carga e alteração dos dados no SGBD de origem durante os testes)
    - examples/ (SQL [DDL] utilizados para criar o banco de origem)
    - sql2config.py (Script de conversao de DDL para JSON no formato adequado ao manifesto pipeline_config.json)
  - workers/ (pipelines de manipulação dos filtros [engines])

---

## Como o MIIDA Funciona

O ciclo de sincronizacao do MIIDA baseia-se em tres etapas automaticas parametrizadas pelo arquivo pipeline_config.json:

1. Provisionamento Idempotente (Schema Cloner): O sistema varre as tabelas listadas no manifesto e gera automaticamente as tabelas de controle interno e as tabelas de negocio no banco moderno de destino com as novas tipagens e nomes definidos. Se as tabelas ja existirem, a estrutura e mantida intacta.
2. Carga Incremental Diferencial (Data Sync): O motor realiza uma consulta no banco de origem buscando apenas os registros cuja coluna de timestamp (como dt_alteracao ou dt_cadastro) seja maior do que a ultima data de sincronizacao registrada no ControlRepository. Cada linha passa pela Camada Anticorrupcao (ACL), que valida o tipo de dado e gera um hash MD5. O registro e inserido ou atualizado no destino.
3. Auditoria de Exclusoes (Limpeza de Orfaos): Periodicamente ou via comando CLI, o sistema executa um cruzamento rapido de chaves primarias (simples ou compostas) convertidas em strings de hash entre a origem e o destino. Registros que foram deletados fisicamente no sistema antigo sao identificados como orfaos e removidos do banco moderno em blocos controlados para evitar locks.

---

## Guia Passo a Passo para reprodução dos experimentos

Siga as instrucoes abaixo para configurar um ambiente local de testes do MIIDA simulando uma migracao entre dois bancos de dados distintos.

### Pre-requisitos
* PHP 8.1 ou superior instalado em linha de comando.
* Extensoes do PDO habilitadas no seu php.ini de acordo com os bancos utilizados (pdo_mysql, pdo_pgsql ou pdo_sqlsrv).
* Acesso a dois bancos de dados locais ou em containers (um para simular o legado de Origem e outro para o destino Moderno).

### Passo 1: Preparar os arquivos de configuracao
1. Crie um arquivo chamado .env na raiz do projeto com as credenciais de acesso aos seus servidores de banco de dados, seguindo o modelo abaixo disponível em .env.example.
2. Copie o arquivo manifesto de exemplo que esta em utils/examples/pipeline_config.json para a pasta /config da raiz do projeto, renomeando-o para pipeline_config.json.
3. Abra o /config/pipeline_config.json e edite os bancos_gerenciados, caso seja reprodução do experiemtno do trabalho, utilize o arquivo utils/data_hadlers/pipeline_config.json, caso seja para aplicação real, você pode utilizar o script utils/sql2config.py para gerar o manifesto a partir de um DDL de origem. O manifesto deve conter a lista de bancos, tabelas e colunas que serao sincronizadas, alem das configuracoes da Camada Anticorrupcao (ACL) para higienização de dados e mapeamento de colunas:
```JSON
 "bancos_gerenciados": [
        {
            "banco_legado": "teste", // Nome do banco de dados de origem (legado)
            "banco_moderno": "teste", // Nome do banco de dados de destino (moderno)
            "tabelas": [
                {
                    "tabela_legada": "tb_clientes_sem_update_1", // Nome da tabela de origem (legado)
                    "tabela_moderna": "tb_clientes_sem_update_1", // Nome da tabela de destino (moderno)
                    "schema_legado": null, // Nome do schema de origem (legado) - null para bancos que nao possuem schema
                    "schema_moderno": "dbo", // Nome do schema de destino (moderno) - null para bancos que nao possuem schema
                    "coluna_last_updated": null, // Nome da coluna de timestamp de ultima atualizacao (legado) - null para tabelas que nao possuem
                    "intervalo_sincronizacao_segundos": 60, // Intervalo em segundos para verificacao de alteracoes na tabela (legado)
                    "camada_anticorrupcao": { // Configuracoes da Camada Anticorrupcao (ACL) para higienizacao e mapeamento de colunas
                        "sanitizacao": {
                            "remover_espacos_excesso": true,
                            "forcar_utf8": true
                        },
                        "mapeamento_colunas": {
                            "id": { // Nome da coluna de origem (legado)
                                "nome_destino": "id", // Nome da coluna de destino (moderno)
                                "tipo": "INT", // Tipo de dado da coluna de destino (moderno)
                                "pk": true // Indica se a coluna e chave primaria (true/false)
                            },
                            "cpf_cnpj": {
                                "nome_destino": "cpf_cnpj",
                                "tipo": "VARCHAR(18)",
                                "not_null": true // Indica se a coluna nao pode ser nula (true/false)
                            }
                        }
                    }
                },
                {
                    "tabela_legada": "tb_clientes_com_update_1",
                    "tabela_moderna": "tb_clientes_com_update_1",
                    "schema_legado": null,
                    "schema_moderno": "dbo",
                    "coluna_last_updated": "last_updated",
                    "intervalo_sincronizacao_segundos": 60,
                    ...
                },
```
 

### Passo 2: Popular o Banco de Dados de Origem (reprodução de experimento)
1. Abra a ferramenta de gerenciamento de banco de dados de sua preferencia (DBeaver, pgAdmin, SSMS) conecte ao SGBD de origem.
2. Va ate a pasta utils/ do projeto e execute o script SQL DDLteste.sql.
3. Este script criara o database teste e 6 tabelas sendo 3 de cada estrutura (com e sem coluna lat_updated).
4. Em seguida, execute os scripts SQL de carga de dados (carga_tb...sql) localizados em utils/data_handlers/ para popular as tabelas com registros de teste.


### Passo 3: Executar os workers para clone e carga inicial
No diretório workers/ foram criados os pipelines que implementam os filtros de cada etapa do processo de sincronizacao. Para executar o worker desejado, utilize o comando:
```bash
php workers/<nome_do_worker>.php
```
Para reprodução dos testes execute os workers clone_infra.php, sync_data.php para a carga inicial.

### Passo 4: Alterar os dados do Banco de origem
Após feita a carga inicial é necessário alterar alguns registros no banco de origem para testar a sincronização incremental. Execute os scripts SQL de alteração localizados em utils/data_handlers/ (utilize o update_25.sql para alterar 25% dos dados).

### Passo 5: Executar o worker de sincronizacao incremental novamente
Após a alteração na base legada, rode o worker/sync_data.php novamente para que o sistema detecte as alterações e sincronize os dados atualizados para o banco moderno de destino.

#### Repita os passos 4 e 5
Dessa vez utilize o update_50.sql para alterar metade da base de dados e sincronize novamente.

### Passo 6: Testar a exclusão de registros e limpeza de órfãos
Utilize o script delete_25.sql para deletar 25% dos registros no banco de origem. Em seguida, execute o worker de limpeza de órfãos (purge_orphans.php) para que o sistema detecte os registros deletados e remova-os do banco moderno de destino.

**Todos os tempos de cada operação em cada tabela serão registrados no logs/miida.log para auditoria e analise de performance.**

## Outros Workers Criados
Tabém foram criados os workers orchestrator.php que executa os filtros de clonagem e sincronização em sequência e depois entra em um loop executando os filtros de sincronização e deleção dos dados órfãos. O worker seed_dev.php foi desenvolvido para criar um ambiente de desenvolvimento ja com dados carregados e pronto para testes de desenvolvimento de novas features.






