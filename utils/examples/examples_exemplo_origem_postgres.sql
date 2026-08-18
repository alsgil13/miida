-- =========================================================
-- MIIDA - Cenário de Testes para o Legado: POSTGRESQL
-- =========================================================

-- Nota: No postgres o banco geralmente deve ser criado antes de se conectar.
-- Este script assume a execução já conectado na database alvo ou no schema público.

CREATE SCHEMA IF NOT EXISTS "public";

-- 1. Tabela com Chave Simples e Boolean Nativo
CREATE TABLE IF NOT EXISTS "public"."tb_usuarios" (
    "cod_usuario" SERIAL PRIMARY KEY,
    "nome_completo" VARCHAR(150) NOT NULL,
    "documento" VARCHAR(20) NULL,
    "flg_ativo" BOOLEAN NOT NULL DEFAULT TRUE,
    "dt_cadastro" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 2. Tabela com Chave Primária Composta
CREATE TABLE IF NOT EXISTS "public"."tb_vendas_itens" (
    "num_venda" INT NOT NULL,
    "seq_item" INT NOT NULL,
    "cod_produto" INT NOT NULL,
    "qtd_vendida" DECIMAL(10,2) NOT NULL,
    "vlr_unitario" DECIMAL(10,2) NOT NULL,
    "dt_alteracao" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY ("num_venda", "seq_item")
);

-- Limpar dados para testes limpos
TRUNCATE TABLE "public"."tb_vendas_itens" RESTART IDENTITY;
TRUNCATE TABLE "public"."tb_usuarios" RESTART IDENTITY;

-- Inserir registros para validações da ACL
INSERT INTO "public"."tb_usuarios" ("nome_completo", "documento", "flg_ativo") VALUES 
('  Joao   Silva Junior  ', '11122233344', TRUE),
('Maria  Eduarda Santos ', '55566677788', TRUE),
(' Carlos Alberto Desativado ', NULL, FALSE);

INSERT INTO "public"."tb_vendas_itens" ("num_venda", "seq_item", "cod_produto", "qtd_vendida", "vlr_unitario") VALUES 
(1001, 1, 50, 2.00, 15.50),
(1001, 2, 55, 1.00, 45.00),
(1002, 1, 50, 10.00, 14.00);
