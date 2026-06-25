<?php

/**
 * MIIDA - Middleware de Ingestao, Integracao e Desacoplamento de Arquiteturas
 * Script CLI de Execucao Unica (One-shot) do Pipeline de Sincronizacao Incremental
 */

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Engine\DataSyncProcessor;

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo 'pipeline_config.json' nao foi encontrado na pasta /config.\n");
}

echo "=========================================================\n";
echo "    MIIDA - INICIANDO PIPELINE DE DADOS (MULTI-SGBD)     \n";
echo "=========================================================\n";

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
    echo "[*] Conectando ao No de Extracao Legado [" . strtoupper($infra['origem_command']['sgbd'] ?? 'mysql') . "]...\n";
    $primeiroBancoLegado = $config['bancos_gerenciados'][0]['banco_legado'] ?? '';
    $connOrigem = ConnectionFactory::getLegadoConnection($infra, $primeiroBancoLegado);

    echo "[*] Conectando ao No de Escrita Moderno [" . strtoupper($infra['destino_query']['sgbd'] ?? 'sqlserver') . "]...\n";
    $primeiroBancoModerno = $config['bancos_gerenciados'][0]['banco_moderno'] ?? 'dw_moderno_db';
    $connModerno = ConnectionFactory::getModernoConnection($infra, $primeiroBancoModerno);

    echo "[OK] Conexoes agnosticas estabelecidas com sucesso.\n\n";

    // 1. Instancia a estrategia de sintaxe do destino para o ControlRepository e Core Engine
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

    // RESET DE CURSORES PARA FORÇAR CARGA INICIAL (FULL LOAD)
    // Limpa registros residuais de monitoramento gerados durante os testes sintaticos.
    // Isso forca o ControlRepository a devolver a data base '1970-01-01', capturando todos os dados legados.
    $tabelaControleAbsoluta = $syntaxModerno->obterNomeQualificado('master', 'dbo', 'miida_controle_sincronizacao');
    $connModerno->exec("TRUNCATE TABLE {$tabelaControleAbsoluta};");
    echo "[*] Cursores temporais resetados. Iniciando modo Carga Inicial...\n\n";

    // Instancia o repositorio de controle passando a conexao moderna e a sintaxe alvo
    $controlRepo = new ControlRepository($connModerno, $syntaxModerno);

    // Instancia o motor do pipeline passando os argumentos na ordem exata esperada pelo construtor
    $processor = new DataSyncProcessor($connOrigem, $connModerno, $controlRepo, $syntaxModerno);

    // 2. Execucao do Pipeline de Sincronizacao iterando sobre o Manifesto Declarativo
    foreach ($config['bancos_gerenciados'] as $bancoConfig) {
        echo "Processando pipeline do Banco de Dados: [{$bancoConfig['banco_legado']}] -> [{$bancoConfig['banco_moderno']}]\n";
        
        foreach ($bancoConfig['tabelas'] as $tabelaConfig) {
            $tabelaLegada  = $tabelaConfig['tabela_legada'];
            $tabelaModerna = $tabelaConfig['tabela_moderna'];
            
            // Garantimos o mapeamento visual correto para dbo no caso do SQL Server
            $schemaModerno = $tabelaConfig['schema_moderno'] ?? 'dbo';
            if ($sgbdDestino === 'sqlserver' && strtolower($schemaModerno) === 'public') {
                $schemaModerno = 'dbo';
            }

            echo "  ├── Sincronizando: [`{$bancoConfig['banco_legado']}`.`{$tabelaLegada}`] -> [[{$bancoConfig['banco_moderno']}].[{$schemaModerno}].[{$tabelaModerna}]]\n";
            
            try {
                $linhasAfetadas = $processor->sincronizarTabela($bancoConfig, $tabelaConfig);
                echo "  │   └── [ OK ] Ciclo concluido. Registros incrementados: {$linhasAfetadas}\n";
            } catch (Exception $eInner) {
                echo "  │   └── [ X ] ERRO NO CICLO: " . $eInner->getMessage() . "\n";
            }
        }
    }

    echo "\n=========================================================\n";
    echo "       PIPELINE DE SINCRONIZACAO UNICA CONCLUIDO!       \n";
    echo "=========================================================\n";

} catch (Exception $e) {
    echo "\n[ X ] ERRO CRITICO NO PIPELINE:\n " . $e->getMessage() . "\n";
    exit(1);
}