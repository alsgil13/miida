<?php

// Script para testar se a Factory e o seu JSON conversam perfeitamente no Lab

require_once __DIR__ . '/src/Database/ConnectionFactory.php';

use Miida\Database\ConnectionFactory;

echo "=========================================================\n";
echo "   MIIDA - TESTE OPERACIONAL DE CONEXÃO MULTIBANCO\n";
echo "=========================================================\n\n";

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo de configuracao 'pipeline_config.json' nao foi encontrado em /config.\n");
}

// 1. Carrega e decodifica as configurações
$config = json_decode(file_get_contents($jsonPath), true);
$infra = $config['configuracao_infraestrutura'];

try {
    echo "[1/2] Tentando estabelecer canal de comunicacao com o Legado (SQL 2005)...<br>\n";
    // Força conexão ao banco padrão 'master' para fins de teste conforme seu script original
    $connLegado = ConnectionFactory::getLegadoConnection($infra, 'master');
    echo " -> SUCESSO! Conexao com o SQL Server 2005 ativa via PDO.<br>\n\n";

    echo "[2/2] Tentando estabelecer canal de comunicacao com o Moderno (SQL 2022)...<br>\n";
    $connModerno = ConnectionFactory::getModernoConnection($infra, 'master');
    echo " -> SUCESSO! Conexao com o SQL Server 2022 ativa via PDO.<br>\n\n";

    echo "=========================================================<br>\n";
    echo " CONCLUSÃO: Infraestrutura validada com sucesso pelo MIIDA!<br>\n";
    echo "=========================================================<br>\n";

} catch (Exception $e) {
    echo "\n<br>⛔ CRÍTICO: Falha na validacao da infraestrutura!<br>\n";
    echo "Mensagem: " . $e->getMessage() . "<br>\n";
    echo "=========================================================<br>\n";
}