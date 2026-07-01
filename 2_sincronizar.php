<?php

/**
 * MIIDA - Script de Sincronizacao de Dados Manual (CLI)
 */

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Database\ControlRepository;
use Miida\Engine\DataSyncProcessor;

echo "=========================================================\n";
echo "          MIIDA - EXECUÇÃO DE CARGA MANUAL (CLI)         \n";
echo "=========================================================\n";

$jsonPath = __DIR__ . '/config/pipeline_config.json';
if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo 'pipeline_config.json' nao encontrado.\n");
}

$config = json_decode(file_get_contents($jsonPath), true);
$infra = $config['configuracao_infraestrutura'];

// --- PROCESSAMENTO DAS VARIÁVEIS DE AMBIENTE (.ENV) ---
foreach (['origem_command', 'destino_query'] as $no) {
    foreach ($infra[$no] as $chave => $valor) {
        if (strpos((string)$valor, 'env:') === 0) {
            $varNome = substr($valor, 4);
            $infra[$no][$chave] = getenv($varNome) ?: ($_ENV[$varNome] ?? ($_SERVER[$varNome] ?? ''));
        }
    }
}

// Atualiza o array global de configuracao com os dados reais desmascarados
$config['configuracao_infraestrutura'] = $infra;

// RESOLUÇÃO DA STRATEGY DO SGBD ORIGEM
$sgbdOrigem = strtolower($infra['origem_command']['sgbd'] ?? 'mysql');
switch ($sgbdOrigem) {
    case 'postgres':
    case 'postgresql': $syntaxLegado = new PostgresSyntax(); break;
    case 'mysql':      $syntaxLegado = new MySqlSyntax(); break;
    case 'sqlserver':
    default:           $syntaxLegado = new SqlServerSyntax(); break;
}

// RESOLUÇÃO DA STRATEGY DO SGBD DESTINO
$sgbdDestino = strtolower($infra['destino_query']['sgbd'] ?? 'sqlserver');
switch ($sgbdDestino) {
    case 'postgres':
    case 'postgresql': $syntaxModerno = new PostgresSyntax(); break;
    case 'mysql':      $syntaxModerno = new MySqlSyntax(); break;
    case 'sqlserver':
    default:           $syntaxModerno = new SqlServerSyntax(); break;
}

try {
    echo "[*] Conectando ao No de Extracao Legado [" . strtoupper($sgbdOrigem) . "]...\n";
    $connLegado = ConnectionFactory::getLegadoConnection($config, $syntaxLegado);
    echo "[OK] Conexão de origem estabelecida com sucesso.\n\n";

    echo "[*] Iniciando varredura dos bancos e tabelas configuradas...\n";
    
    // O laço percorre cada escopo de banco isolado no mesmo servidor destino
    foreach ($config['bancos_gerenciados'] as $banco) {
        
        // CAPTURA DINÂMICA: Descobre o banco lógico desta iteração (ex: dw_moderno_db)
        $bancoModernoAlvo = $banco['banco_moderno'];
        
        // Instancia ou recupera do cache a conexão PDO apontando diretamente para este banco
        $connModerno = ConnectionFactory::getModernoConnection($config, $syntaxModerno, $bancoModernoAlvo);
        
        // Instancia o repositório de controle e o processador no contexto correto deste banco
        $controlRepo = new ControlRepository($connModerno, $syntaxModerno);
        $processor = new DataSyncProcessor($connLegado, $connModerno, $syntaxLegado, $syntaxModerno, $controlRepo);

        foreach ($banco['tabelas'] as $tabela) {
            echo " -> Sincronizando no Banco [{$bancoModernoAlvo}] -> Tabela: {$tabela['tabela_moderna']}... ";
            
            $linhas = $processor->sincronizarTabela($banco, $tabela);
            
            echo "[OK] ({$linhas} registros afetados)\n";
        }
    }

    echo "\n=========================================================\n";
    echo "[OK] SINCRONIZAÇÃO CONCLUÍDA COM SUCESSO!\n";
    echo "=========================================================\n";

} catch (Exception $e) {
    echo "\nERRO CRÍTICO NA EXECUÇÃO: " . $e->getMessage() . "\n";
    exit(1);
}