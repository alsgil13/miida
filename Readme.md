# MIIDA — Middleware de Ingestão, Integração e Desacoplamento de Arquiteturas

O **MIIDA** é um middleware de alto desempenho desenvolvido em PHP CLI para atuar como motor de consistência eventual e sincronização diferencial de dados. O sistema foi projetado especificamente para solucionar os desafios de transição arquitetural e a adoção do padrão **CQRS (Command Query Responsibility Segregation)**, isolando um nó de escrita legado (SQL Server 2005) de um nó moderno de leitura de alta performance (SQL Server 2022).

---

## Arquitetura e Diferenciais Técnicos (Defesa de TCC)

* **Abordagem Não-Intrusiva:** O ecossistema opera sem instalar gatilhos (*triggers*), criar tabelas de log ou alterar qualquer estrutura no banco de dados de produção do passado (SQL Server 2005).
* **Camada Anticorrupção (ACL) Declarativa:** Mapeia, traduz nomes de colunas e efetua a coerção estrita de tipos (*Type Casting*) baseando-se em metadados JSON, blindando o novo banco de dados contra codificações corrompidas (CP1252/ISO-8859-1 para UTF-8).
* **Idempotência Baseada em Hash de Versão:** Cada registro processado possui uma assinatura digital MD5 calculada em tempo de execução. Se os dados de origem não mudaram, o motor ignora a escrita, reduzindo o overhead de I/O de rede e disco.
* **Suporte Nativo a Chaves Primárias Compostas:** O motor e o provisionador tratam relacionamentos complexos e chaves múltiplas agrupadas de forma automatizada através de comandos relacionais atômicos (`MERGE` / `UPSERT`).
* **Stateful Integration e Resiliência (Self-Healing):** O estado temporal do pipeline é gerenciado centralizadamente no destino pelo `ControlRepository` na tabela técnica do banco `master`, suportando quedas de conectividade e reinicializações de contêineres sem duplicar ou perder registros.

---

## Estrutura de Pastas do Projeto

```text
miida/
├── config/
│   └── pipeline_config.json      # Centralização declarativa da infra e mapeamento de tabelas
├── logs/
│   └── miida.log                 # Arquivo físico de telemetria e logs de infraestrutura
├── src/
│   ├── Database/
│   │   ├── ConnectionFactory.php # Fábrica multithread de conexões PDO (Dblib / SqlSrv)
│   │   └── ControlRepository.php # Guardião relacional do estado temporal das tabelas
│   ├── Engine/
│   │   ├── SchemaCloner.php      # Provisionador idempotente de bancos e tabelas estruturais
│   │   └── DataSyncProcessor.php # Core Engine: Orquestrador de Extração, ACL e Upsert
│   └── Services/
│   │   ├── AntiCorruptionLayer.php # Higienizador de strings, tipos e gerador de hashes
│   │   └── Logger.php            # Subsistema híbrido de log (Arquivo .log + Banco de Dados)
├── inicializar.php               # Script CLI de provisionamento e carga estrutural inicial
├── orquestrador.php              # Daemon principal (Worker Process) em loop contínuo
├── testar_acl.php                # Script de teste unitário isolado da ACL
├── testar_control.php            # Script de teste unitário isolado de persistência de estado
└── README.md                     # Documentação técnica do ecossistema