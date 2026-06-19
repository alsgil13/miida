<?php

/**
 * MIIDA - Middleware de Ingestão, Integração e Desacoplamento de Arquiteturas
 * Script CLI de Inicialização, Provisionamento e Auditoria de Infraestrutura
 */

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Engine\SchemaCloner;

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo 'pipeline_config.json' não foi encontrado na pasta /config.\n");
}

echo "=========================================================\n";
echo "           MIIDA - INICIALIZANDO SUBSISTEMA             \n";
echo "=========================================================\n";
echo "[*] Carregando mapa de metadados declarativo...\n";

$config = json_decode(file_get_contents($jsonPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("ERRO: O arquivo JSON possui erros de sintaxe: " . json_last_error_msg() . "\n");
}

$infra = $config['configuracao_infraestrutura'];

// --- PROCESSAMENTO DAS VARIÁVEIS DE AMBIENTE (.ENV) ---
foreach (['origem_command', 'destino_query'] as $no) {
    foreach ($infra[$no] as $chave => $valor) {
        if (strpos((string)$valor, 'env:') === 0) {
            $envVarName = substr($valor, 4);
            $infra[$no][$chave] = getenv($envVarName) ?: '';
        }
    }
}

try {
    echo "[...] Estabelecendo conexão inicial com o nó moderno (SQL 2022)...\n";
    $connModerno = ConnectionFactory::getModernoConnection($infra, 'master');
    echo " -> Conexão ativa via driver: " . ($infra['destino_query']['driver'] ?? 'padrão') . "\n\n";

    // FASE 1: AUTO-PROVISIONAMENTO DA TABELA DE CONTROLE OPERACIONAL (MIIDA)
    echo "[1/2] Verificando repositório de controle operacional...\n";
    
    $sqlTabelaControle = "
        IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'miida_controle_sincronizacao')
        BEGIN
            CREATE TABLE miida_controle_sincronizacao (
                banco_nome VARCHAR(128) NOT NULL,
                tabela_nome VARCHAR(128) NOT NULL,
                ultima_sincronizacao DATETIME NOT NULL,
                status_execucao VARCHAR(32) NOT NULL,
                registros_afetados INT NOT NULL DEFAULT 0,
                PRIMARY KEY (banco_nome, tabela_nome)
            );
        END;
    ";
    
    $connModerno->exec($sqlTabelaControle);
    echo " -> Tabela [master].[dbo].[miida_controle_sincronizacao] checada/criada com sucesso.\n\n";

    // Tabela de Log Histórico de Eventos
    $sqlTabelaLogs = "
        IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'miida_log_eventos')
        BEGIN
            CREATE TABLE miida_log_eventos (
                id INT IDENTITY(1,1) PRIMARY KEY,
                data_evento DATETIME NOT NULL DEFAULT GETDATE(),
                nivel VARCHAR(16) NOT NULL,
                componente VARCHAR(64) NOT NULL,
                mensagem VARCHAR(MAX) NOT NULL,
                detalhes_tecnicos VARCHAR(MAX) NULL
            );
        END;
    ";
    $connModerno->exec($sqlTabelaLogs);
    
    echo " -> Tabelas de controle e logs checadas/criadas com sucesso no banco master.\n\n";

    // FASE 2: CLONAGEM E ENGENHARIA REVERSA DOS BANCOS DE NEGÓCIO
    echo "[2/2] Iniciando clonagem declarativa dos esquemas de negócio...\n";
    $cloner = new SchemaCloner($connModerno);
    $cloner->clonar($config['bancos_gerenciados']);

    echo "\n[ OK ] Todo o ecossistema (Controle + Negócio) foi provisionado com sucesso!\n";
    echo "=========================================================\n";

} catch (Exception $e) {
    echo "\n[ X ] ERRO DURANTE A EXECUÇÃO DO MIDDLEWARE:\n";
    echo "Mensagem: " . $e->getMessage() . "\n";
    echo "=========================================================\n";
} finally {
    ConnectionFactory::killConnections();
    echo "[...] Conexões finalizadas de forma segura.\n";
}