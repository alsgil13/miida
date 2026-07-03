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

                // 3. Monta o mapa bruto de colunas para a Strategy assumir 100% da sintaxe final
                $colunas = [];
                $pks = [];
                foreach ($mapeamento as $colunaOrigem => $props) {
                    $nomeColDestino = $props['nome_destino'] ?? $colunaOrigem;
                    $tipoDestino = strtoupper($props['tipo'] ?? $props['tipo_destino'] ?? 'VARCHAR(255)');

                    if ($tipoDestino === 'TEXT') {
                        $tipoDestino = $this->syntax->obterTipoTextoLongo();
                    }

                    $colunas[$nomeColDestino] = $tipoDestino;

                    if (!empty($props['pk'])) {
                        $pks[] = $nomeColDestino;
                    }
                }

                // Injeta coluna técnica de tracking apenas como fallback quando o manifesto não a definiu.
                $colunaLastUpdated = $tabelaConfig['coluna_last_updated'] ?? null;
                if (!empty($colunaLastUpdated) && !array_key_exists($colunaLastUpdated, $colunas)) {
                    $colunas[$colunaLastUpdated] = $this->syntax->obterTipoDataHora();
                }

                if (empty($colunaLastUpdated) && !array_key_exists('middleware_last_updated', $colunas)) {
                    $colunas['middleware_last_updated'] = $this->syntax->obterTipoDataHora();
                }

                if (!array_key_exists('hash_versao', $colunas)) {
                    $colunas['hash_versao'] = 'VARCHAR(32)';
                }

                if (!empty($pks)) {
                    $pks = array_values(array_unique($pks));
                }

                // 4. Executa a criação física da tabela delegando totalmente para a Strategy ativa
                $sqlCriarTabela = $this->syntax->obterDdlCriarTabela($schemaModerno, $tabelaModerna, $colunas, $pks);

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