<?php

namespace Miida\Engine;

use Miida\Database\ControlRepository;
use Miida\Database\Syntax\SgbdSyntaxInterface;
use Miida\Services\AntiCorruptionLayer;

/**
 * MIIDA - DataSyncProcessor
 * Motor de Sincronizacao de Dados de Alta Performance Multi-SGBD
 */
class DataSyncProcessor
{
    private \PDO $connLegado;
    private \PDO $connModerno;
    private SgbdSyntaxInterface $syntaxLegado;
    private SgbdSyntaxInterface $syntaxModerno;
    private ControlRepository $controlRepo;

    public function __construct(
        \PDO $connLegado,
        \PDO $connModerno,
        SgbdSyntaxInterface $syntaxLegado,
        SgbdSyntaxInterface $syntaxModerno,
        ControlRepository $controlRepo
    ) {
        $this->connLegado   = $connLegado;
        $this->connModerno  = $connModerno;
        $this->syntaxLegado = $syntaxLegado;
        $this->syntaxModerno = $syntaxModerno;
        $this->controlRepo  = $controlRepo;
    }

    /**
     * Sincroniza uma tabela individual baseando-se no Manifesto JSON
     */

/**
     * Sincroniza uma tabela individual baseando-se no Manifesto JSON
     */

/**
     * Sincroniza uma tabela individual baseando-se no Manifesto JSON
     */
/**
     * Sincroniza uma tabela individual baseando-se no Manifesto JSON
     */
    public function sincronizarTabela(array $configBanco, array $configTabela): int
    {
        $nomeBancoOrigem   = $configBanco['banco_legado'];
        $nomeBancoDestino  = $configBanco['banco_moderno'];
        $schemaDestino     = $configTabela['schema_moderno'] ?? 'dbo';
        $tabelaDestino     = $configTabela['tabela_moderna'];
        $tabelaOrigem      = $configTabela['tabela_legada'];
        
        // $tabelaQualificada = "[{$nomeBancoDestino}].[{$schemaDestino}].[{$tabelaDestino}]";
        $tabelaQualificada = $this->syntaxModerno->obterNomeQualificado($nomeBancoDestino, $schemaDestino, $tabelaDestino);
        $mapeamento = $configTabela['camada_anticorrupcao']['mapeamento_colunas'] ?? [];

        if (empty($mapeamento)) {
            throw new \Exception("Erro de Modelagem: O bloco [mapeamento_colunas] nao foi encontrado.");
        }

        // Identifica as PKs usando o nome_destino do JSON
        $pks = [];
        foreach ($mapeamento as $colOriginal => $props) {
            if (!empty($props['pk'])) {
                $pks[] = $props['nome_destino'] ?? $colOriginal;
            }
        }
        if (empty($pks)) {
            throw new \Exception("Erro de Modelagem: Chaves Primarias nao mapeadas.");
        }

        $ultimaData = $this->obterDataUltimaSincronizacao($nomeBancoDestino, $tabelaDestino);
        $this->controlRepo->atualizarVersao($nomeBancoDestino, $tabelaDestino, 'PROCESSANDO', 0);
        $colunaIncremental = $configTabela['coluna_timestamp_controle'] ?? null;

        $sqlExtracao = "SELECT * FROM `{$nomeBancoOrigem}`.`{$tabelaOrigem}`";
        if ($ultimaData && !empty($colunaIncremental)) {
            $sqlExtracao .= " WHERE `{$colunaIncremental}` > :ultima_data";
        }

        $stmtOrigem = $this->connLegado->prepare($sqlExtracao);
        if ($ultimaData && !empty($colunaIncremental)) {
            $stmtOrigem->bindValue(':ultima_data', $ultimaData);
        }
        $stmtOrigem->execute();

        $linhasProcessadas = 0;

        try {
            // while ($registroBruto = $stmtOrigem->fetch(\PDO::FETCH_ASSOC)) {
                
            //     // 1. A ACL já processa os dados, traduz os nomes das colunas e injeta o hash_versao
            //     $registroLimpo = AntiCorruptionLayer::processar($registroBruto, $configTabela);

            //     if (empty($registroLimpo)) {
            //         continue;
            //     }

            //     // 2. ENVIAMOS O REGISTRO LIMPO INTEIRO. 
            //     // Sem loops adicionais de filtragem ou remontagem que possam corromper as colunas.
            //     $this->syntaxModerno->executarUpsert($this->connModerno, $tabelaQualificada, $registroLimpo, $pks);
            //     $linhasProcessadas++;
            // }
            while ($registroBruto = $stmtOrigem->fetch(\PDO::FETCH_ASSOC)) {
                
                // A ACL processa, altera os nomes para o destino e injeta o hash_versao
                $registroLimpo = AntiCorruptionLayer::processar($registroBruto, $configTabela);

                if (empty($registroLimpo)) {
                    continue;
                }

                // ENVIAMOS COMPLETO E DIRETO PARA A STRATEGY DO SQL SERVER
                $this->syntaxModerno->executarUpsert($this->connModerno, $tabelaQualificada, $registroLimpo, $pks);
                $linhasProcessadas++;
            }            

        } catch (\Exception $e) {
            $this->controlRepo->atualizarVersao($nomeBancoDestino, $tabelaDestino, 'FALHA', $linhasProcessadas);
            throw $e;
        }

        $this->controlRepo->atualizarVersao($nomeBancoDestino, $tabelaDestino, 'SUCESSO', $linhasProcessadas);

        return $linhasProcessadas;
    }

    // public function sincronizarTabela(array $configBanco, array $configTabela): int
    // {
    //     $nomeBancoOrigem   = $configBanco['banco_legado'];
    //     $nomeBancoDestino  = $configBanco['banco_moderno'];
    //     $schemaDestino     = $configTabela['schema_moderno'] ?? 'dbo';
    //     $tabelaDestino     = $configTabela['tabela_moderna'];
    //     $tabelaOrigem      = $configTabela['tabela_legada'];
        
    //     // Define o nome qualificado estrutural da tabela destino no SQL Server
    //     $tabelaQualificada = "[{$nomeBancoDestino}].[{$schemaDestino}].[{$tabelaDestino}]";

    //     // Acessa o mapeamento dentro do nó 'camada_anticorrupcao' igual ao seu JSON
    //     $mapeamento = $configTabela['camada_anticorrupcao']['mapeamento_colunas'] ?? [];

    //     if (empty($mapeamento)) {
    //         throw new \Exception("Erro de Modelagem: O bloco [mapeamento_colunas] nao foi encontrado dentro de [camada_anticorrupcao] para a tabela [{$tabelaDestino}].");
    //     }

    //     // 1. BLINDAGEM MÁXIMA: Identifica as Chaves Primárias tanto pelo nome de origem quanto destino
    //     $pks = [];
    //     foreach ($mapeamento as $colOriginal => $props) {
    //         if (!empty($props['pk'])) {
    //             // Guarda o nome de destino (ex: 'id')
    //             $pks[] = $props['nome_destino'] ?? $colOriginal;
    //             // Adiciona também o nome original como mapeamento alternativo direto (ex: 'cod_usuario')
    //             $pks[] = $colOriginal;
    //         }
    //     }

    //     // Garante valores únicos no array de PKs para não duplicar tokens SQL
    //     $pks = array_unique($pks);

    //     if (empty($pks)) {
    //         throw new \Exception("Erro de Modelagem: A tabela [{$tabelaDestino}] nao possui Chaves Primarias (pk: true) mapeadas no JSON.");
    //     }

    //     // 2. Recupera a data da última sincronização bem-sucedida
    //     $ultimaData = $this->obterDataUltimaSincronizacao($nomeBancoDestino, $tabelaDestino);

    //     // 3. Registra o início da execução na tabela técnica de controle
    //     $this->controlRepo->atualizarVersao($nomeBancoDestino, $tabelaDestino, 'PROCESSANDO', 0);

    //     // 4. Mapeia a coluna incremental real baseada na chave do seu JSON (coluna_timestamp_controle)
    //     $colunaIncremental = $configTabela['coluna_timestamp_controle'] ?? null;

    //     // 5. Monta a query delta de extração incremental na Origem (MySQL) devidamente qualificada
    //     $sqlExtracao = "SELECT * FROM `{$nomeBancoOrigem}`.`{$tabelaOrigem}`";
    //     if ($ultimaData && !empty($colunaIncremental)) {
    //         $sqlExtracao .= " WHERE `{$colunaIncremental}` > :ultima_data";
    //     }

    //     $stmtOrigem = $this->connLegado->prepare($sqlExtracao);
    //     if ($ultimaData && !empty($colunaIncremental)) {
    //         $stmtOrigem->bindValue(':ultima_data', $ultimaData);
    //     }
    //     $stmtOrigem->execute();

    //     $linhasProcessadas = 0;

    //     // 6. Inicia o loop atómico de carga registro por registro
    //     $this->connModerno->beginTransaction();
    //     try {
    //         while ($registroBruto = $stmtOrigem->fetch(\PDO::FETCH_ASSOC)) {
                
    //             // Passa os dados pela ACL purificada (Higienização dinâmica)
    //             $registroLimpo = AntiCorruptionLayer::processar($registroBruto, $configTabela);

    //             // Se por acaso a ACL devolver um registro com as chaves originais mas o SQL Server precisar das novas,
    //             // criamos um mapeamento dinâmico inline para garantir consistência total dos campos aceitos na tabela
    //             $registroProntoParaPersistir = [];
    //             foreach ($registroLimpo as $chave => $valor) {
    //                 // Se a chave for original (ex: 'cod_usuario') e existir um nome_destino mapeado (ex: 'id'), projeta
    //                 if (isset($mapeamento[$chave]['nome_destino'])) {
    //                     $registroProntoParaPersistir[$mapeamento[$chave]['nome_destino']] = $valor;
    //                 } else {
    //                     // Mantém a chave como veio da ACL
    //                     $registroProntoParaPersistir[$chave] = $valor;
    //                 }
    //             }

    //             // Executa a Strategy de persistência do SQL Server de forma segura
    //             $this->syntaxModerno->executarUpsert($this->connModerno, $tabelaQualificada, $registroProntoParaPersistir, $pks);
                
    //             $linhasProcessadas++;
    //         }
            
    //         $this->connModerno->commit();

    //     } catch (\Exception $e) {
    //         $this->connModerno->rollBack();
    //         $this->controlRepo->atualizarVersao($nomeBancoDestino, $tabelaDestino, 'FALHA', $linhasProcessadas);
    //         throw $e;
    //     }

    //     // 7. Atualiza o status final de sucesso da esteira de dados
    //     $this->controlRepo->atualizarVersao($nomeBancoDestino, $tabelaDestino, 'SUCESSO', $linhasProcessadas);

    //     return $linhasProcessadas;
    // }

    /**
     * Busca o timestamp da última execução bem-sucedida
     */
    private function obterDataUltimaSincronizacao(string $banco, string $tabela): ?string
    {
        $tabelaControle = $this->syntaxModerno->obterNomeQualificadoTabelaControle();

        $sql = "SELECT ultima_sincronizacao 
                FROM {$tabelaControle} 
                WHERE banco_nome = :banco 
                  AND tabela_nome = :tabela 
                  AND status_execucao = 'SUCESSO'";

        try {
            $stmt = $this->connModerno->prepare($sql);
            $stmt->bindValue(':banco', $banco);
            $stmt->bindValue(':tabela', $tabela);
            $stmt->execute();
            
            $resultado = $stmt->fetchColumn();
            return $resultado ? $resultado : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}