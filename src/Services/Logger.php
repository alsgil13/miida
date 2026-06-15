<?php

namespace Miida\Services;

use PDO;
use Exception;

class Logger
{
    private ?PDO $connModerno;
    private string $logFolder;
    private string $logFile;

    /**
     * O construtor aceita a conexão do banco moderno de forma opcional.
     * Se o banco cair, o Logger continua gravando no arquivo físico de forma segura!
     */
    public function __construct(?PDO $connModerno = null)
    {
        $this->connModerno = $connModerno;
        $this->logFolder = __DIR__ . '/../../logs';
        $this->logFile = $this->logFolder . '/miida.log';

        // Garante que a pasta /logs exista na raiz do projeto
        if (!is_dir($this->logFolder)) {
            mkdir($this->logFolder, 0777, true);
        }
    }

    /**
     * Método central de registro de logs
     */
    public function log(string $nivel, string $componente, string $mensagem, ?string $detalhes = null): void
    {
        $dataAtual = date('Y-m-d H:i:s');

        // 1. GRAVAÇÃO NO ARQUIVO FÍSICO (.log) - À prova de falhas de banco
        $linhaLog = sprintf("[%s] [%s] [%s]: %s %s\n", $dataAtual, strtoupper($nivel), $componente, $mensagem, $detalhes ? "({$detalhes})" : "");
        file_put_contents($this->logFile, $linhaLog, FILE_APPEND);

        // 2. GRAVAÇÃO NA TABELA DO BANCO DE DADOS (Se a conexão estiver ativa)
        if ($this->connModerno) {
            try {
                $sql = "INSERT INTO [master].[dbo].[miida_log_eventos] (data_evento, nivel, componente, mensagem, detalhes_tecnicos) 
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
                $linhaErroBanco = sprintf("[%s] [CRITICAL] [Logger]: Falha ao persistir log no SQL Server: %s\n", $dataAtual, $e->getMessage());
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