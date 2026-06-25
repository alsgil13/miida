<?php

namespace Miida\Engine;

use Exception;
use PDO;
use Miida\Database\Syntax\SgbdSyntaxInterface;
use Miida\Services\Logger;

class LimpezaOrfaosProcessor 
{
    private PDO $connLegado;
    private PDO $connModerno;
    private SgbdSyntaxInterface $syntaxLegado;
    private SgbdSyntaxInterface $syntaxModerno;
    private Logger $logger;

    public function __construct(
        PDO $connLegado, 
        PDO $connModerno, 
        SgbdSyntaxInterface $syntaxLegado,
        SgbdSyntaxInterface $syntaxModerno,
        Logger $logger
    ) {
        $this->connLegado = $connLegado;
        $this->connModerno = $connModerno;
        $this->syntaxLegado = $syntaxLegado;
        $this->syntaxModerno = $syntaxModerno;
        $this->logger = $logger;
    }

    public function executarLimpeza(array $banco, array $tabela): void
    {
        $bancoLegado   = $banco['banco_legado'];
        $bancoModerno  = $banco['banco_moderno'];
        $tabelaLegada  = $tabela['tabela_legada'];
        $tabelaModerna = $tabela['tabela_moderna'];
        $schemaModerno = $tabela['schema_moderno'] ?? null;
        $schemaLegado  = $tabela['schema_legado'] ?? null;

        $tabelaFqLegado  = $this->syntaxLegado->obterNomeQualificado($bancoLegado, $schemaLegado, $tabelaLegada);
        $tabelaFqModerno = $this->syntaxModerno->obterNomeQualificado($bancoModerno, $schemaModerno, $tabelaModerna);

        $componenteNome = "LimpezaOrfaosProcessor -> " . $tabelaModerna;
        echo "  Processando auditoria de orfaos na tabela " . $tabelaModerna . "\n";

        try {
            $chavesPrimarias = [];
            $mapeamento = $tabela['camada_anticorrupcao']['mapeamento_colunas'];
            
            foreach ($mapeamento as $colunaOriginal => $detalhes) {
                if (isset($detalhes['pk']) && $detalhes['pk'] === true) {
                    $chavesPrimarias[] = [
                        'origem'  => $colunaOriginal,
                        'destino' => $detalhes['nome_destino'] ?? $colunaOriginal
                    ];
                }
            }

            if (empty($chavesPrimarias)) {
                return;
            }

            $pksOrigem  = array_column($chavesPrimarias, 'origem');
            $pksDestino = array_column($chavesPrimarias, 'destino');

            // 1. EXTRAÇÃO DINÂMICA DAS CHAVES EXISTENTES NA ORIGEM (LEGADO)
            $concatOrigem = $this->syntaxLegado->obterSqlConcat($pksOrigem);
            $sqlOrigem = "SELECT " . $concatOrigem . " AS chave_composta FROM " . $tabelaFqLegado;
            
            $stmtOrigem = $this->connLegado->prepare($sqlOrigem);
            $stmtOrigem->execute();
            $chavesOrigem = $stmtOrigem->fetchAll(PDO::FETCH_COLUMN, 0);

            // 2. EXTRAÇÃO DINÂMICA DAS CHAVES EXISTENTES NO DESTINO (MODERNO)
            $concatDestino = $this->syntaxModerno->obterSqlConcat($pksDestino);
            $sqlDestino = "SELECT " . $concatDestino . " AS chave_composta FROM " . $tabelaFqModerno;
            
            $stmtDestino = $this->connModerno->prepare($sqlDestino);
            $stmtDestino->execute();
            $chavesDestino = $stmtDestino->fetchAll(PDO::FETCH_COLUMN, 0);

            // Identifica chaves que existem no destino mas ja nao constam na origem (Orfaos)
            $chavesOrfas = array_diff($chavesDestino, $chavesOrigem);

            if (empty($chavesOrfas)) {
                echo "    Nenhum registro orfao detetado para remocao.\n";
                return;
            }

            // 3. REMOÇÃO DOS ÓRFÃOS EM BATCHES PARA NÃO TRAVAR O BANCO
            $loteDelecao = array_chunk($chavesOrfas, 500);
            $totalDeletado = 0;

            foreach ($loteDelecao as $lote) {
                $clausulasOr = [];
                $params = [];
                $i = 0;

                foreach ($lote as $chaveComposta) {
                    $partes = explode('-', $chaveComposta);
                    $clausulasAnd = [];
                    
                    foreach ($pksDestino as $index => $colunaPk) {
                        $paramKey = ":p_" . $colunaPk . "_" . $i;
                        $clausulasAnd[] = $colunaPk . " = " . $paramKey;
                        $params[$paramKey] = $partes[$index] ?? '';
                    }
                    
                    $clausulasOr[] = "(" . implode(" AND ", $clausulasAnd) . ")";
                    $i++;
                }

                $sqlDelete = "DELETE FROM " . $tabelaFqModerno . " WHERE " . implode(" OR ", $clausulasOr);
                $stmtDelete = $this->connModerno->prepare($sqlDelete);
                $stmtDelete->execute($params);
                
                $totalDeletado += $stmtDelete->rowCount();
            }

            if ($totalDeletado > 0) {
                $this->logger->warning($componenteNome, "Registros orfaos expurgados do ambiente moderno. Total de linhas removidas: " . $totalDeletado);
                echo "    Sucesso. Foram removidos " . $totalDeletado . " registros orfaos no destino.\n";
            }

        } catch (Exception $e) {
            $this->logger->error($componenteNome, "Falha ao executar rotina de limpeza de orfaos", $e->getMessage());
            echo "    Erro ao processar limpeza da tabela: " . $e->getMessage() . "\n";
        }
    }
}