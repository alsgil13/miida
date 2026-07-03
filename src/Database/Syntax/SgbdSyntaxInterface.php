<?php 

namespace Miida\Database\Syntax;

interface SgbdSyntaxInterface 
{
    // Resolve o formato do nome: [bd].[schema].[tabela] ou `bd`.`tabela`
    public function obterNomeQualificado(string $banco, ?string $schema, string $tabela): string;
    
    // Retorna a query exata para criar um schema se ele não existir
    public function obterDdlCriarSchema(string $schema): string;
    
    // Retorna a query exata para criar a tabela se não existir
    public function obterDdlCriarTabela(?string $schema, string $tabela, array $colunas, array $pks): string;
    
    // Retorna o comando de Upsert/Merge específico daquele SGBD
    public function obterSqlUpsert(string $tabelaQualificada, array $colunas, array $chavesPrimarias): string;

    //Retorna o comando de concatenação de múltiplas colunas separado por hífen para chaves compostas
    public function obterSqlConcat(array $colunas): string;

    //Escapa o nome de uma coluna ou identificador de acordo com o dialeto do SGBD
    public function escaparColuna(string $coluna): string;

    // Retorna o comando SQL nativo para alterar o contexto/catálogo do banco de dados corrente.
    public function obterComandoTrocaBanco(string $banco): string;

    // Retorna o tipo de dado ideal para armazenamento de strings/textos longos.
    public function obterTipoTextoLongo(): string;

    // Retorna o tipo de dado correto para timestamp/data e hora técnica de controle.
    public function obterTipoDataHora(): string;

    // Monta a string DSN do PDO com base nos parâmetros de rede.
    public function obterDsn(string $host, int $port, string $banco): string;

    // Retorna o nome da base administrativa padrão do SGBD (ex: master, postgres, mysql).
    public function obterBancoAdministrativo(): string;

    /**
     * Retorna o SQL necessário para verificar e criar um banco de dados de forma segura.
     * Caso o SGBD dê suporte a 'IF NOT EXISTS', pode ser uma única query, caso contrário,
     * retorna um array com a query de checagem e a de criação.
     */
    public function obterDdlGarantirBanco(string $bancoAlvo): array;

    // Retorna o nome qualificado específico para a tabela interna de controle de sincronização do middleware.
    public function obterNomeQualificadoTabelaControle(): string;

    // Monta o SQL agnóstico de extração incremental para o banco de origem.
    public function obterSqlSelecaoIncremental(string $banco, string $tabela, string $colunaControle): string;

    /**
     * Gera e executa o comando atômico ou instrução estruturada de UPSERT (Merge/Insert or Update)
     * apropriado e otimizado para o dialeto do SGBD de destino.
     */
    public function executarUpsert(\PDO $destino, string $tabelaQualificada, array $registro, array $pks, array $tabelaConfig): void;

    /**
     * Gera o DDL para a tabela de controle
     * @return string
     */
    public function getDDLControle(): string;
}