<?php

/**
 * MIIDA - Script de Inicializacao e Provisionamento Estrutural Automatizado (Multi-SGBD)
 */

require_once __DIR__ . '/autoload.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\Syntax\SqlServerSyntax;
use Miida\Database\Syntax\MySqlSyntax;
use Miida\Database\Syntax\PostgresSyntax;
use Miida\Engine\SchemaCloner;

echo "=========================================================\n";
echo "      MIIDA - INICIALIZANDO SUBSISTEMA (MULTI-SGBD)      \n";
echo "=========================================================\n";

$jsonPath = __DIR__ . '/config/pipeline_config.json';
if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo 'pipeline_config.json' nao encontrado.\n");
}

$config = json_decode(file_get_contents($jsonPath), true);
$infra = $config['configuracao_infraestrutura'];

// --- PROCESSAMENTO DAS VARIAVEIS DE AMBIENTE (.ENV) ---
foreach (['origem_command', 'destino_query'] as $no) {
    foreach ($infra[$no] as $chave => $valor) {
        if (strpos((string)$valor, 'env:') === 0) {
            $infra[$no][$chave] = getenv(substr($valor, 4)) ?: '';
        }
    }
}

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

echo "[*] Carregando mapa de metadados declarativo...\n";
echo " -> Motor Origem:  [" . strtoupper($sgbdOrigem) . "]\n";
echo " -> Motor Destino: [" . strtoupper($sgbdDestino) . "]\n";

try {
    // [PASSO 1/3] PROVISIONANDO BASES DE DADOS FISICAS
    echo "\n[1/3] Garantindo a existencia dos bancos de dados no destino...\n";
    foreach ($config['bancos_gerenciados'] as $bancoConfig) {
        $bancoModerno = $bancoConfig['banco_moderno'];
        echo " -> Verificando/Criando catalogo: [{$bancoModerno}]\n";
        ConnectionFactory::getModernoConnection($infra, $syntaxModerno, $bancoModerno);
    }

    // [PASSO 2/3] CRIAÇÃO DA TABELA TÉCNICA INTERNA DO MIDDLEWARE
    echo "\n[2/3] Criando tabela interna de controle de auditoria temporal...\n";
    $conexaoAdmin = ConnectionFactory::getModernoConnection($infra, $syntaxModerno, $syntaxModerno->obterBancoAdministrativo());
    $tabelaControle = $syntaxModerno->obterNomeQualificadoTabelaControle();

    $ddlTabelaControle = "
        CREATE TABLE {$tabelaControle} (
            banco_nome VARCHAR(100) NOT NULL,
            tabela_nome VARCHAR(150) NOT NULL,
            ultima_sincronizacao DATETIME NOT NULL,
            status_execucao VARCHAR(20) NOT NULL,
            registros_afetados INT NOT NULL,
            PRIMARY KEY (banco_nome, tabela_nome)
        );
    ";

    if ($sgbdDestino === 'postgres' || $sgbdDestino === 'postgresql') {
        $ddlTabelaControle = str_replace('DATETIME', 'TIMESTAMP', $ddlTabelaControle);
    }

    try {
        $conexaoAdmin->exec($ddlTabelaControle);
        echo " -> Tabela tecnica [{$tabelaControle}] provisionada com sucesso.\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'already') !== false || strpos($e->getMessage(), 'existe') !== false) {
            echo " -> [INFO] Tabela tecnica ja existente no ambiente. Pulando criacao.\n";
        } else {
            throw $e;
        }
    }

    // [PASSO 3/3] CLONAGEM AUTOMÁTICA DAS TABELAS DE NEGÓCIO
    echo "\n[3/3] Executando Engenharia Reversa e Clonagem Estrutural de Negocio...\n";
    
    // ATENÇÃO AQUI: Precisamos abrir a conexão com a ORIGEM também para o Cloner inspecionar as colunas!
    $connLegado  = ConnectionFactory::getLegadoConnection($infra, $syntaxLegado);
    $connModerno = ConnectionFactory::getModernoConnection($infra, $syntaxModerno);
    
    // Se o seu SchemaCloner antigo precisava da conexão de origem, passamos ela aqui de forma explícita
    // De acordo com os padrões, passamos a conexão de destino no construtor
    $cloner = new SchemaCloner($connModerno, $syntaxModerno);
    
    // Executa a clonagem estrutural forçando a exibição de erros reais na tela se falhar
    foreach ($config['bancos_gerenciados'] as $banco) {
        foreach ($banco['tabelas'] as $tabela) {
            echo " -> Provisionando tabela: {$banco['banco_moderno']}.{$tabela['schema_moderno']}.{$tabela['tabela_moderna']}... ";
            
            // O cloner executa a engenharia reversa.
            $cloner->clonar([$banco]);
            echo "[OK]\n";
        }
    }

    echo "\n=========================================================\n";
    echo "[OK] SUBSISTEMA MIIDA INICIALIZADO E PROVISIONADO COMPLETO!\n";
    echo "=========================================================\n";

} catch (Exception $e) {
    echo "\nERRO CRÍTICO NO PROVISIONAMENTO REAL: " . $e->getMessage() . "\n";
    exit(1);
}