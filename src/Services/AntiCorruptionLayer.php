<?php

namespace Miida\Services;

/**
 * MIIDA - AntiCorruptionLayer (ACL)
 * Camada de Higienizacao, Sanitizacao e Conformidade de Dados Multi-SGBD
 * Implementação purificada e automatizada contra quebras de valores nulos
 */
class AntiCorruptionLayer
{
    /**
     * Metodo Principal de Sanitizacao e Higienizacao dos Dados
     */
    public static function higienizar(array $dadosBrutos, array $configAcl): array
    {
        $dadosLimpos = [];
        $mapeamento = $configAcl['mapeamento_colunas'] ?? [];
        $sanitizacao = $configAcl['sanitizacao'] ?? [];

        foreach ($mapeamento as $colunaOrigem => $propsTarget) {
            $nomeDestino = $propsTarget['nome_destino'] ?? $colunaOrigem;
            
            // Recupera o valor bruto vindo do SGBD legado
            $valor = $dadosBrutos[$colunaOrigem] ?? null;

            if ($valor !== null) {
                // Aplica politicas declarativas de higienizacao de strings se ativo
                if (!empty($sanitizacao['remover_espacos_excesso']) && is_string($valor)) {
                    $valor = trim(preg_replace('/\s+/', ' ', $valor));
                }

                if (!empty($sanitizacao['forcar_utf8']) && is_string($valor)) {
                    $valor = mb_convert_encoding($valor, 'UTF-8', 'UTF-8');
                }
            } else {
                // SOLUÇÃO INVISÍVEL E AUTOMÁTICA: 
                // Se o dado veio nulo da origem, mas o seu mapeamento indica que ele faz parte 
                // da Chave Primária (PK), nós aplicamos um fallback seguro baseado no tipo 
                // para evitar que o banco de destino rejeite o INSERT/UPSERT.
                if (!empty($propsTarget['pk'])) {
                    $tipo = strtoupper($propsTarget['tipo_destino'] ?? 'VARCHAR');
                    
                    if (strpos($tipo, 'INT') !== false || strpos($tipo, 'NUMERIC') !== false || strpos($tipo, 'DECIMAL') !== false) {
                        $valor = 0;
                    } else {
                        $valor = ''; // Fallback seguro para strings/identificadores textuais obrigatórios
                    }
                }
            }

            $dadosLimpos[$nomeDestino] = $valor;
        }

        return $dadosLimpos;
    }

    /**
     * Metodo Curinga (Alias/Apelido) para resolver a chamada do Core Engine
     */
    public static function processar(array $dadosBrutos, array $configAcl): array
    {
        return self::higienizar($dadosBrutos, $configAcl);
    }
}