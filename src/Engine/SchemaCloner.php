<?php

namespace Miida\Engine;

use PDO;
use Exception;
use Miida\Database\Syntax\SgbdSyntaxInterface;

/**
 * MIIDA - SchemaCloner
 * Mecanismo de Engenharia Reversa e Clonagem Estrutural Declarativa (Multi-SGBD)
 * Implementação Pura do Padrão Strategy (100% Agnóstica)
 */
class SchemaCloner
{
    private PDO $destino;
    private SgbdSyntaxInterface $syntax;

    public function __construct(PDO $destino, SgbdSyntaxInterface $syntax)
    {
        $this->destino = $destino;
        $this->syntax = $syntax;
    }

    /**
     * Executa o provisionamento estrutural baseado nos bancos gerenciados do manifesto JSON
     */
    public function clonar(array $bancosGerenciados): void
    {
        foreach ($bancosGerenciados as $bancoConfig) {
            $bancoModerno = $bancoConfig['banco_moderno'];

            // 1. Delega a instrução de mudança de contexto de banco à Strategy ativa
            $sqlMudarBanco = $this->syntax->obterComandoTrocaBanco($bancoModerno);
            if (!empty($sqlMudarBanco)) {
                $this->destino->exec($sqlMudarBanco);
            }

            foreach ($bancoConfig['tabelas'] as $tabelaConfig) {
                $tabelaModerna = $tabelaConfig['tabela_moderna'];
                $schemaModerno = $tabelaConfig['schema_moderno'] ?? 'dbo';
                $mapeamento    = $tabelaConfig['camada_anticorrupcao']['mapeamento_colunas'] ?? [];

                // 2. Cria o Schema lógico se o SGBD der suporte (A Strategy resolve e normaliza se necessário)
                $sqlSchema = $this->syntax->obterDdlCriarSchema($schemaModerno);
                if (!empty($sqlSchema)) {
                    try {
                        $stmtSchema = $this->destino->query($sqlSchema);
                        if ($stmtSchema) {
                            $stmtSchema->closeCursor();
                        }
                    } catch (Exception $e) {
                        // Ignora se o schema já existir no ambiente destino
                    }
                }

                // 3. Monta dinamicamente as colunas do DDL usando a Strategy de escape e tipos
                $colunasDdl = [];
                foreach ($mapeamento as $colunaOrigem => $props) {
                    $nomeColDestino = $props['nome_destino'];
                    $tipoDestino    = strtoupper($props['tipo_destino'] ?? 'VARCHAR(255)');
                    
                    // Se o tipo original for TEXT, delega para a Strategy decidir a melhor representação física
                    if ($tipoDestino === 'TEXT') {
                        $tipoDestino = $this->syntax->obterTipoTextoLongo();
                    }

                    $restricao = !empty($props['pk']) ? ' NOT NULL' : '';
                    
                    // Escapa a coluna de forma agnóstica via Strategy
                    $colunasDdl[] = $this->syntax->escaparColuna($nomeColDestino) . " {$tipoDestino}{$restricao}";
                }

                // Injeta as colunas técnicas de auditoria e rastreabilidade sem fixar delimitadores brutos
                $colunaLastUpdated = $tabelaConfig['coluna_last_updated'] ?? 'middleware_last_updated';
                $tipoDataHoraTecnica = $this->syntax->obterTipoDataHora();

                $colunasDdl[] = $this->syntax->escaparColuna($colunaLastUpdated) . " {$tipoDataHoraTecnica} NOT NULL";
                $colunasDdl[] = $this->syntax->escaparColuna('hash_versao') . " VARCHAR(32) NOT NULL";

                // 4. Mapeia chaves primárias utilizando as regras semânticas corretas
                $pks = [];
                foreach ($mapeamento as $colunaOrigem => $props) {
                    if (!empty($props['pk'])) {
                        $pks[] = $this->syntax->escaparColuna($props['nome_destino']);
                    }
                }
                
                if (!empty($pks)) {
                    $colunasDdl[] = "PRIMARY KEY (" . implode(', ', $pks) . ")";
                }

                $corpoTabelaSql = implode(",\n        ", $colunasDdl);

                // 5. Executa a criação física da tabela delegando totalmente para a Strategy ativa
                // O método obterDdlCriarTabela passa a receber o schema real resolvido pela própria Strategy
                $sqlCriarTabela = $this->syntax->obterDdlCriarTabela($schemaModerno, $tabelaModerna, $corpoTabelaSql);

                try {
                    $stmtTable = $this->destino->query($sqlCriarTabela);
                    if ($stmtTable) {
                        $stmtTable->closeCursor();
                    }
                } catch (Exception $e) {
                    // Evita quebra caso a tabela já exista no ambiente moderno
                    if (strpos($e->getMessage(), 'already') === false && strpos($e->getMessage(), 'exist') === false) {
                        throw new Exception("Falha ao criar tabela de negócio {$tabelaModerna}: " . $e->getMessage());
                    }
                }
            }
        }
    }
}