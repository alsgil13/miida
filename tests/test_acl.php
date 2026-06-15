<?php

/**
 * MIIDA - Script de Teste Unitário/Funcional da Camada Anticorrupção (ACL)
 */

require_once __DIR__ . '/src/Services/AntiCorruptionLayer.php';

use Miida\Services\AntiCorruptionLayer;

echo "=========================================================\n";
echo "       MIIDA - TESTE OPERACIONAL DA ACL (SERVICES)       \n";
echo "=========================================================\n\n";

$jsonPath = __DIR__ . '/config/pipeline_config.json';

if (!file_exists($jsonPath)) {
    die("ERRO: Arquivo pipeline_config.json nao encontrado.\n");
}

$config = json_decode(file_get_contents($jsonPath), true);

// Vamos capturar as configurações específicas da tabela 'orientadores' do seu JSON
$configTabelaOrientadores = null;
foreach ($config['bancos_gerenciados'] as $banco) {
    if ($banco['banco_legado'] === 'Dados_pg') {
        foreach ($banco['tabelas'] as $tabela) {
            if ($tabela['tabela_legada'] === 'orientadores') {
                $configTabelaOrientadores = $tabela;
                break 2;
            }
        }
    }
}

if (!$configTabelaOrientadores) {
    die("ERRO: Configuração da tabela 'orientadores' não foi localizada no JSON.\n");
}

// 1. Simulação de um dado "sujo" extraído do SQL Server 2005 (Mundo Legado)
// Strings com espaços gerados pelo CHAR do SQL antigo e caracteres especiais ISO-8859-1
$linhaBrutaLegada = [
    'area'            => "  1050   ", 
    'codusp'          => "99887766",
    'data_inicio'     => "2026-01-01 10:00:00",
    'data_fim'        => null,
    'so_mestrado'     => "\x01", // Representação de BIT ativo vindo do banco antigo
    'pontual'         => "\x00", // BIT inativo
    'id_usuario'      => "42",
    'data'            => "2026-06-07 12:00:00",
    'ativo'           => "\x01"
];

echo "[*] Massa de dados bruta simulada do SQL Server 2005:\n";
print_r($linhaBrutaLegada);

// 2. Instancia a ACL a partir da pasta Services
$acl = new AntiCorruptionLayer();

// 3. Processa a linha usando as regras declarativas do JSON
$linhaHigienizada = $acl->processarLinha($linhaBrutaLegada, $configTabelaOrientadores);

echo "\n[✔] Resultado processado e higienizado pela ACL do MIIDA:\n";
print_r($linhaHigienizada);

echo "=========================================================\n";
echo "ANÁLISE DO TESTE:\n";
echo " -> Remoção de espaços (Trim): " . ($linhaHigienizada['area'] === 1050 ? "SUCESSO" : "FALHA") . "\n";
echo " -> Coerção estrita (Type Casting INT): " . (is_int($linhaHigienizada['area']) ? "SUCESSO" : "FALHA") . "\n";
echo " -> Coerção estrita (Type Casting BIT): " . (is_int($linhaHigienizada['ativo']) && ($linhaHigienizada['ativo'] === 1 || $linhaHigienizada['ativo'] === 0) ? "SUCESSO" : "FALHA") . "\n";
echo " -> Geração do Hash de Versão MD5: " . (!empty($linhaHigienizada['hash_versao']) ? "SUCESSO (" . $linhaHigienizada['hash_versao'] . ")" : "FALHA") . "\n";
echo "=========================================================\n";