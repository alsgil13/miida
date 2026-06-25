<?php

/**
 * MIIDA - Middleware de Ingestao, Integracao e Desacoplamento de Arquiteturas
 * Script CLI de Inicializacao, Provisionamento e Auditoria de Infraestrutura (Multi-SGBD)
 */

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Engine\SchemaCloner;

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo 'pipeline_config.json' nao foi encontrado na pasta /config.\n");
}

echo "=========================================================\n";
echo "      MIIDA - INICIALIZANDO SUBSISTEMA (MULTI-SGBD)      \n";
echo "=========================================================\n";
echo "[*] Carregando mapa de metadados declarativo...\n";

$config = json_decode(file_get_contents($jsonPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("ERRO: O arquivo JSON possui erros de sintaxe: " . json_last_error_msg() . "\n");
}

$infra = $config['configuracao_infraestrutura'];

// --- PROCESSAMENTO DAS VARIAVEIS DE AMBIENTE (.ENV) ---
foreach (['origem_command', 'destino_query'] as $no) {
    foreach ($infra[$no] as $chave => $valor) {
        if (strpos((string)$valor, 'env:') === 0) {
            $infra[$no][$chave] = getenv(substr($valor, 4)) ?: '';
        }
    }
}

try {
    // 1. IDENTIFICACAO E INSTANCIACAO DO PADRAO STRATEGY PARA O DESTINO
    $sgbdDestino = strtolower($infra['destino_query']['sgbd'] ?? 'sqlserver');
    
    switch ($sgbdDestino) {
        case 'postgres':
        case 'postgresql':
            $syntaxModerno = new PostgresSyntax();
            break;
        case 'mysql':
            $syntaxModerno = new MySqlSyntax();
            break;
        case 'sqlserver':
        default:
            $syntaxModerno = new SqlServerSyntax();
            break;
    }

    echo " -> Motores detetados. Destino configurado como: [" . strtoupper($sgbdDestino) . "]\n";
    echo "[1/2] Provisionando bases de dados e preparando metadados...\n";

    // PROVISIONAMENTO AUTONOMO DE BANCOS DE DADOS
    // Varre todos os bancos listados no manifesto e aciona a factory para cria-los dinamicamente
    foreach ($config['bancos_gerenciados'] as $bancoConfig) {
        $bancoAlvo = $bancoConfig['banco_moderno'];
        echo " -> Garantindo a existencia do banco moderno de destino: [{$bancoAlvo}]\n";
        
        // Passar o parametro opcional de banco_alvo sinaliza a Factory para interceptar falhas
        // e rodar o CREATE DATABASE caso ele ainda nao exista nativamente no SGBD
        ConnectionFactory::getModernoConnection($infra, $bancoAlvo);
    }

    // Estabelece a conexao definitiva no primeiro banco moderno listado para gerenciar as tabelas tecnicas
    $primeiroBancoModerno = $config['bancos_gerenciados'][0]['banco_moderno'] ?? 'dw_moderno_db';
    $connModerno = ConnectionFactory::getModernoConnection($infra, $primeiroBancoModerno);

    // Resolve os nomes qualificados das tabelas globais baseado no dialeto do banco ativo
    $tabelaControle = $syntaxModerno->obterNomeQualificado($primeiroBancoModerno, 'dbo', 'miida_controle_sincronizacao');
    $tabelaLogs     = $syntaxModerno->obterNomeQualificado($primeiroBancoModerno, 'dbo', 'miida_logs_sistema');

    // Executa a criacao do Schema logico (se o SGBD der suporte, ex: Postgres)
    $sqlSchemaMaster = $syntaxModerno->obterDdlCriarSchema('dbo');
    $connModerno->exec($sqlSchemaMaster);

    // FASE 1: PROVISIONAMENTO DAS TABELAS DE INTEGRALIDADE E MIDDLEWARE
    
    // Tabela de Controle de Cursores Temporais (Estrutura ANSI compativel com todos os SGBDs)
    $corpoControle = "
        banco_nome VARCHAR(128) NOT NULL,
        tabela_nome VARCHAR(128) NOT NULL,
        ultima_sincronizacao DATETIME NOT NULL,
        status_execucao VARCHAR(16) NOT NULL,
        registros_afetados INT NOT NULL,
        PRIMARY KEY (banco_nome, tabela_nome)
    ";
    // Ajusta o tipo DATETIME para TIMESTAMP se for Postgres para manter compatibilidade nativa de tipos
    if ($sgbdDestino === 'postgres' || $sgbdDestino === 'postgresql') {
        $corpoControle = str_replace('DATETIME', 'TIMESTAMP', $corpoControle);
    }
    
    $sqlCreateTableControle = $syntaxModerno->obterDdlCriarTabela('dbo', 'miida_controle_sincronizacao', $corpoControle);
    $connModerno->exec($sqlCreateTableControle);

    // Tabela Centralizada de Auditoria e Logs do MIIDA
    $corpoLogs = "
        id INT AUTO_INCREMENT PRIMARY KEY,
        data_log DATETIME NOT NULL,
        nivel VARCHAR(16) NOT NULL,
        componente VARCHAR(64) NOT NULL,
        mensagem TEXT NOT NULL,
        detalhes TEXT NULL
    ";
    // Ajustes finos sintaticos por dialeto para a tabela de logs
    if ($sgbdDestino === 'postgres' || $sgbdDestino === 'postgresql') {
        $corpoLogs = "
            id SERIAL PRIMARY KEY,
            data_log TIMESTAMP NOT NULL,
            nivel VARCHAR(16) NOT NULL,
            componente VARCHAR(64) NOT NULL,
            mensagem TEXT NOT NULL,
            detalhes TEXT NULL
        ";
    } elseif ($sgbdDestino === 'sqlserver') {
        $corpoLogs = "
            id INT IDENTITY(1,1) PRIMARY KEY,
            data_log DATETIME NOT NULL,
            nivel VARCHAR(16) NOT NULL,
            componente VARCHAR(64) NOT NULL,
            mensagem VARCHAR(MAX) NOT NULL,
            detalhes VARCHAR(MAX) NULL
        ";
    }

    $sqlCreateTableLogs = $syntaxModerno->obterDdlCriarTabela('dbo', 'miida_logs_sistema', $corpoLogs);
    $connModerno->exec($sqlCreateTableLogs);
    
    echo " -> Tabelas de controle e logs checadas/criadas com sucesso no no de destino.\n\n";

    // FASE 2: CLONAGEM E ENGENHERIA REVERSA DOS BANCOS DE NEGOCIO
    echo "[2/2] Iniciando clonagem declarativa dos esquemas de negocio...\n";
    
    // Injeta a estrategia de sintaxe diretamente no Cloner multi-SGBD
    $cloner = new SchemaCloner($connModerno, $syntaxModerno);
    $cloner->clonar($config['bancos_gerenciados']);

    echo "\n[ OK ] Todo o ecossistema (Controle + Negocio) foi provisionado com sucesso!\n";
    echo "=========================================================\n";

} catch (Exception $e) {
    echo "\n[ X ] ERRO CRITICO DURANTE A EXECUCAO:\n " . $e->getMessage() . "\n";
    exit(1);
}