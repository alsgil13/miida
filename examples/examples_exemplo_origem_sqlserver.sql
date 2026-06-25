-- =========================================================
-- MIIDA - Cenário de Testes para o Legado: SQL SERVER
-- =========================================================

-- Criar Banco de Dados de Teste Legado se não existir
IF NOT EXISTS (SELECT * FROM sys.databases WHERE name = 'erp_legado_db')
BEGIN
    CREATE DATABASE erp_legado_db;
END;
GO

USE erp_legado_db;
GO

-- 1. Tabela com Chave Simples e tipos comuns que testam sanitização
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID('[dbo].[tb_usuarios]') AND type = 'U')
BEGIN
    CREATE TABLE [dbo].[tb_usuarios] (
        [cod_usuario] INT IDENTITY(1,1) PRIMARY KEY,
        [nome_completo] VARCHAR(150) NOT NULL,
        [documento] VARCHAR(20) NULL,
        [flg_ativo] BIT NOT NULL DEFAULT 1,
        [dt_cadastro] DATETIME NOT NULL DEFAULT GETDATE()
    );
END;

-- 2. Tabela com Chave Primária Composta (Perfeita para testar a engine de órfãos)
IF NOT EXISTS (SELECT * FROM sys.objects WHERE object_id = OBJECT_ID('[dbo].[tb_vendas_itens]') AND type = 'U')
BEGIN
    CREATE TABLE [dbo].[tb_vendas_itens] (
        [num_venda] INT NOT NULL,
        [seq_item] INT NOT NULL,
        [cod_produto] INT NOT NULL,
        [qtd_vendida] DECIMAL(10,2) NOT NULL,
        [vlr_unitario] DECIMAL(10,2) NOT NULL,
        [dt_alteracao] DATETIME NOT NULL DEFAULT GETDATE(),
        PRIMARY KEY ([num_venda], [seq_item])
    );
END;
GO

-- Limpar dados anteriores para o teste idempotente
TRUNCATE TABLE [dbo].[tb_vendas_itens];
-- Como truncate não funciona com identity ativo se houver FK, usamos delete
DELETE FROM [dbo].[tb_usuarios];
DBCC CHECKIDENT ('[dbo].[tb_usuarios]', RESEED, 0);
GO

-- Inserir dados de teste (adicionando espaços extras de propósito nas strings para testar a ACL)
INSERT INTO [dbo].[tb_usuarios] ([nome_completo], [documento], [flg_ativo]) VALUES 
('  Joao   Silva Junior  ', '11122233344', 1),
('Maria  Eduarda Santos ', '55566677788', 1),
(' Carlos Alberto Desativado ', NULL, 0);

INSERT INTO [dbo].[tb_vendas_itens] ([num_venda], [seq_item], [cod_produto], [qtd_vendida], [vlr_unitario]) VALUES 
(1001, 1, 50, 2.00, 15.50),
(1001, 2, 55, 1.00, 45.00),
(1002, 1, 50, 10.00, 14.00);
GO
