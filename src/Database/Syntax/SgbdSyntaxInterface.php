<?php 

namespace Miida\Database\Syntax;

interface SgbdSyntaxInterface 
{
    // Resolve o formato do nome: [bd].[schema].[tabela] ou `bd`.`tabela`
    public function obterNomeQualificado(string $banco, ?string $schema, string $tabela): string;
    
    // Retorna a query exata para criar um schema se ele não existir
    public function obterDdlCriarSchema(string $schema): string;
    
    // Retorna a query exata para criar a tabela se não existir
    public function obterDdlCriarTabela(string $schema, string $tabela, string $corpoColunas): string;
    
    // Retorna o comando de Upsert/Merge específico daquele SGBD
    public function obterSqlUpsert(string $tabelaQualificada, array $colunas, array $chavesPrimarias): string;

    //Retorna o comando de concatenação de múltiplas colunas separado por hífen para chaves compostas
    public function obterSqlConcat(array $colunas): string;
}