<?php

namespace Miida\Services;

use PDO;
use Exception;
use Miida\Database\Syntax\SgbdSyntaxInterface;

class Logger
{
    private ?PDO $connModerno;
    private string $logFolder;
    private string $logFile;
    private string $tabelaLogs;

    /**
     * O construtor agora aceita opcionalmente a estratégia de sintaxe do SGBD de destino
     */
    public function __construct(?PDO $connModerno = null, ?SgbdSyntaxInterface $syntax = null)
    {
        $this->connModerno = $connModerno;
        $this->logFolder = __DIR__ . '/../../logs';
        $this->logFile = $this->logFolder . '/miida.log';

        // Garante que a pasta /logs exista na raiz do projeto
        if (!is_dir($this->logFolder)) {
            mkdir($this->logFolder, 0777, true);
        }

        // Se houver uma estratégia de sintaxe injetada, qualifica a tabela de logs dinamicamente
        if ($syntax !== null) {
            $this->tabelaLogs = $syntax->obterNomeQualificado('master', 'dbo', 'miida_logs_sistema');
        } else {
            $this->tabelaLogs = '"public"."miida_logs_sistema"'; // Fallback genérico ANSI
        }
    }

    /**
     * Método central de registro de logs
     */
    public function log(string $nivel, string $componente, string $mensagem, ?string $detalhes = null): void
    {
        $dataAtual = date('Y-m-d H:i:s');

        // 1. GRAVAÇÃO NO ARQUIVO FÍSICO (.log) - À prova de falhas de banco
        $linhaLog = sprintf("[%s] [%s] [%s]: %s %s\n", $dataAtual, strtoupper($nivel), strtoupper($componente), $mensagem, $detalhes ? "| Detalhes: " . $detalhes : "");
        file_put_contents($this->logFile, $linhaLog, FILE_APPEND);

        // 2. PERSISTÊNCIA NO BANCO DE DADOS DE LEITURA/AUDITORIA
        if ($this->connModerno !== null) {
            try {
                // Query com o nome de tabela dinâmico e parâmetros ANSI padrão funcionais em qualquer SGBD
                $sql = "INSERT INTO {$this->tabelaLogs} (data_log, nivel, componente, mensagem, detalhes) 
                        VALUES (:data, :nivel, :componente, :mensagem, :detalhes)";
                
                $stmt = $this->connModerno->prepare($sql);
                $stmt->execute([
                    ':data'       => $dataAtual,
                    ':nivel'      => strtoupper($nivel),
                    ':componente' => $componente,
                    ':mensagem'   => $mensagem,
                    ':detalhes'   => $detalhes
                ]);
            } catch (Exception $e) {
                // Se falhar o insert no banco, grava o erro da falha no próprio arquivo de log físico
                $linhaErroBanco = sprintf("[%s] [CRITICAL] [Logger]: Falha ao persistir log no SGBD de Destino: %s\n", $dataAtual, $e->getMessage());
                file_put_contents($this->logFile, $linhaErroBanco, FILE_APPEND);
            }
        }
    }

    // Atalhos semânticos elegantes para o código ficar limpo
    public function info(string $componente, string $mensagem): void { $this->log('INFO', $componente, $mensagem); }
    public function success(string $componente, string $mensagem, ?string $detalhes = null): void { $this->log('SUCCESS', $componente, $mensagem, $detalhes); }
    public function warning(string $componente, string $mensagem): void { $this->log('WARNING', $componente, $mensagem); }
    public function error(string $componente, string $mensagem, ?string $detalhes = null): void { $this->log('ERROR', $componente, $mensagem, $detalhes); }
}