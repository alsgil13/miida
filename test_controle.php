<?php

/**
 * MIIDA - Script de Teste Unitário/Funcional do ControlRepository
 */

require_once __DIR__ . '/src/Database/ConnectionFactory.php';
require_once __DIR__ . '/src/Database/ControlRepository.php';

use Miida\Database\ConnectionFactory;
use Miida\Database\ControlRepository;

echo "=========================================================\n";
echo "      MIIDA - TESTE OPERACIONAL DO CONTROL REPOSITORY    \n";
echo "=========================================================\n\n";

$jsonPath = __DIR__ . '/config/pipeline_config.json';
$config = json_decode(file_get_contents($jsonPath), true);
$infra = $config['configuracao_infraestrutura'];

try {
    // 1. Conecta ao banco master do SQL Server 2022
    $connModerno = ConnectionFactory::getModernoConnection($infra, 'master');
    $repo = new ControlRepository($connModerno);

    // 2. Testando a leitura de uma tabela que nunca foi sincronizada
    echo "[*] Testando leitura de tabela inédita (esperado ano 1970)...\n";
    $dataInicial = $repo->obterUltimaDataSincronizacao('Dados_pg', 'orientadores');
    echo " -> Data retornada: " . $dataInicial . "\n\n";

    // 3. Testando a gravação/atualização de estado (Simulando uma sincronização de sucesso)
    $agora = date('Y-m-d H:i:s');
    echo "[*] Gravando estado de sincronização bem-sucedido para 'orientadores'...\n";
    $repo->atualizarEstadoSincronizacao('Dados_pg', 'orientadores', 'SUCESSO', 15, $agora);
    echo " -> Estado gravado.\n\n";

    // 4. Lendo novamente para ver se o valor mudou do ano 1970 para o timestamp atual
    echo "[*] Relendo metadados para confirmar persistência...\n";
    $dataAtualizada = $repo->obterUltimaDataSincronizacao('Dados_pg', 'orientadores');
    echo " -> Nova data retornada: " . $dataAtualizada . "\n\n";

    if ($dataAtualizada === $agora) {
        echo "=========================================================\n";
        echo " RESULTADO DO TESTE: SUCESSO COESIVO DE PERSISTÊNCIA!\n";
        echo "=========================================================\n";
    } else {
        echo " -> Atenção: Ocorreu uma divergência nos timestamps armazenados.\n";
    }

} catch (Exception $e) {
    echo "⛔ FALHA CRÍTICA NO REPOSITÓRIO DE CONTROLE: " . $e->getMessage() . "\n";
} finally {
    ConnectionFactory::killConnections();
}