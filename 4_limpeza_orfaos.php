<?php

/**
 * MIIDA - Middleware de Ingestão, Integração e Desacoplamento de Arquiteturas
 * Script Auxiliar CLI para Auditoria e Expurgos de Registros Órfãos (Deleções)
 */

namespace Miida\Engine;

use Exception;
use PDO;
use Miida\Database\ConnectionFactory;
use Miida\Services\Logger;

class LimpezaOrfaosProcessor 
{
    private $connLegado;
    private $connModerno;
    private $logger;

    public function __construct($connLegado, $connModerno, $logger)
    {
        $this->connLegado = $connLegado;
        $this->connModerno = $connModerno;
        $this->logger = $logger;
    }

    public function executarLimpeza(array $banco, array $tabela)
    {
        $bancoLegado   = $banco['banco_legado'];
        $bancoModerno  = $banco['banco_moderno'];
        $tabelaLegada  = $tabela['tabela_legada'];
        $tabelaModerna = $tabela['tabela_moderna'];
        $chavePrimaria = $tabela['chave_primaria'] ?? 'id';

        $chaveCronometro = "{$bancoModerno}.{$tabelaModerna}";
        echo "\n [" . date('H:i:s') . "] Iniciando auditoria de órfãos em: [{$chaveCronometro}]\n";

        // 1. Marca o início do cronômetro
        $inicioMili = microtime(true);

        try {
            // Coleta todas as PKs ativas no banco Legado (Origem)
            $sqlLegado = "SELECT {$chavePrimaria} FROM {$bancoLegado}.dbo.{$tabelaLegada}";
            $stmtLegado = $this->connLegado->query($sqlLegado);
            $pksLegado = $stmtLegado->fetchAll(PDO::FETCH_COLUMN, 0);

            // Coleta todas as PKs existentes no banco Moderno (Destino)
            $sqlModerno = "SELECT {$chavePrimaria} FROM {$bancoModerno}.dbo.{$tabelaModerna}";
            $stmtModerno = $this->connModerno->query($sqlModerno);
            $pksModerno = $stmtModerno->fetchAll(PDO::FETCH_COLUMN, 0);

            // Identifica o que tem no Moderno mas NÃO tem no Legado (órfãos)
            $pksDeletadas = array_diff($pksModerno, $pksLegado);
            $registrosExcluidos = 0;

            if (!empty($pksDeletadas)) {
                $totalParaDeletar = count($pksDeletadas);
                echo "   -> Detectados {$totalParaDeletar} registros órfãos para expurgo.\n";

                // Executa a deleção em lotes de 500 para proteger o SGBD
                $lotes = array_chunk($pksDeletadas, 500);
                foreach ($lotes as $lote) {
                    $inClause = implode(',', array_map(function($id) {
                        return is_numeric($id) ? $id : "'{$id}'";
                    }, $lote));

                    $sqlDelete = "DELETE FROM {$bancoModerno}.dbo.{$tabelaModerna} WHERE {$chavePrimaria} IN ({$inClause})";
                    $registrosExcluidos += $this->connModerno->exec($sqlDelete);
                }
            }

            // 2. Finaliza o cronômetro e calcula a diferença em milissegundos
            $fimMili = microtime(true);
            $tempoGastoMili = round(($fimMili - $inicioMili) * 1000, 2);

            // 3. Impressão formatada em tela
            if ($registrosExcluidos > 0) {
                echo " [" . date('H:i:s') . "] Concluído: [{$chaveCronometro}] -> {$registrosExcluidos} registros deletados em {$tempoGastoMili} ms\n";
            } else {
                echo " [" . date('H:i:s') . "] Concluído: [{$chaveCronometro}] -> Nenhum órfão encontrado. Sincronia perfeita em {$tempoGastoMili} ms\n";
            }

            // 4. Gravação estruturada respeitando a assinatura do seu Logger:
            // Componente: "LimpezaOrfaos"
            // Mensagem: Resumo do que aconteceu
            // Detalhes (3º argumento do seu success): JSON String com os dados técnicos
            $detalhesJson = json_encode([
                'tabela' => $chaveCronometro,
                'registros_deletados' => $registrosExcluidos,
                'tempo_processamento_ms' => $tempoGastoMili
            ], JSON_UNESCAPED_UNICODE);

            $this->logger->success(
                "LimpezaOrfaos",
                "Auditoria e expurgo de órfãos concluído para {$chaveCronometro}", 
                $detalhesJson
            );

            return $registrosExcluidos;

        } catch (Exception $e) {
            $fimMili = microtime(true);
            $tempoGastoMili = round(($fimMili - $inicioMili) * 1000, 2);
            
            echo " [❌ " . date('H:i:s') . "] Falha ao auditar órfãos em [{$chaveCronometro}] após {$tempoGastoMili} ms. Erro: " . $e->getMessage() . "\n";
            
            // Gravação estruturada de erro respeitando seu Logger
            $detalhesErroJson = json_encode([
                'erro' => $e->getMessage(),
                'tempo_decorrido_ms' => $tempoGastoMili
            ], JSON_UNESCAPED_UNICODE);

            $this->logger->error(
                "LimpezaOrfaos",
                "Falha na auditoria de órfãos da tabela {$chaveCronometro}",
                $detalhesErroJson
            );
            throw $e;
        }
    }
}

// Inicialização via CLI (quando chamado direto no terminal: php 4_limpeza_orfaos.php)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    require_once __DIR__ . '/autoload.php';

    $jsonPath = __DIR__ . '/config/pipeline_config.json';
    if (!file_exists($jsonPath)) {
        die("ERRO: pipeline_config.json ausente.\n");
    }

    $config = json_decode(file_get_contents($jsonPath), true);
    $infra = $config['configuracao_infraestrutura'];

    // Tradução das variáveis do .env
    foreach (['origem_command', 'destino_query'] as $no) {
        foreach ($infra[$no] as $chave => $valor) {
            if (strpos((string)$valor, 'env:') === 0) {
                $infra[$no][$chave] = getenv(substr($valor, 4)) ?: '';
            }
        }
    }

    try {
        $connLegado  = ConnectionFactory::getLegadoConnection($infra, 'master');
        $connModerno = ConnectionFactory::getModernoConnection($infra, 'master');
        $logger      = new Logger($connModerno);

        $limpador = new LimpezaOrfaosProcessor($connLegado, $connModerno, $logger);

        echo "=========================================================\n";
        echo "          MIIDA - AUDITORIA DE EXCLUSÕES MANUAL          \n";
        echo "=========================================================\n";
        
        $inicioCiclo = microtime(true);
        
        foreach ($config['bancos_gerenciados'] as $banco) {
            foreach ($banco['tabelas'] as $tabela) {
                $limpador->executarLimpeza($banco, $tabela);
            }
        }
        
        $tempoCicloMili = round((microtime(true) - $inicioCiclo) * 1000, 2);
        echo "\n=========================================================\n";
        echo "[✔] Ciclo global de auditoria finalizado em {$tempoCicloMili} ms\n";
        echo "=========================================================\n";
        
    } catch (Exception $e) {
        echo "\nERRO FATAL NA EXECUÇÃO ISOLADA: " . $e->getMessage() . "\n";
    } finally {
        ConnectionFactory::killConnections();
    }
}