import json
import re

# ==============================================================================
# CONFIGURAÇÕES DE ENTRADA, SAÍDA E SANITIZAÇÃO
# ==============================================================================
ARQUIVO_SQL_ENTRADA = "ddl_teste.sql"  # Altere o nome do arquivo aqui
ARQUIVO_JSON_SAIDA = "pipeline_config.json"

# Flags de sanitização pedidas
REMOVER_ESPACOS_PADRAO = True
FORCAR_UTF8_PADRAO = True

# ==============================================================================
# MOTOR DE EXTRAÇÃO PARSER SQL (MÁQUINA DE ESTADOS - ULTRA-CONFIÁVEL)
# ==============================================================================
def extrair_bancos_e_tabelas_literal(caminho_sql):
    with open(caminho_sql, "r", encoding="utf-8", errors="ignore") as f:
        linhas_arquivo = f.readlines()

    nome_banco_legado = "banco_legado_detectado"
    lista_tabelas_mapeadas = []
    
    # Variáveis de controle para a leitura linha por linha
    dentro_de_tabela = False
    nome_tabela_atual = None
    linhas_corpo_tabela = []

    for linha in linhas_arquivo:
        linha_limpa = linha.strip()
        
        # 1. Detecta o nome do banco
        if not dentro_de_tabela and "CREATE DATABASE" in linha.upper():
            banco_match = re.search(r"CREATE\s+DATABASE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`'\"\[]?([a-zA-Z0-9_\-]+)[`'\"\]]?", linha, re.IGNORECASE)
            if banco_match:
                nome_banco_legado = banco_match.group(1)
            continue

        # 2. Detecta o início de uma tabela
        if "CREATE TABLE" in linha.upper() and not dentro_de_tabela:
            tabela_match = re.search(r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`'\"\[]?([a-zA-Z0-9_\-]+)[`'\"\]]?", linha, re.IGNORECASE)
            if tabela_match:
                dentro_de_tabela = True
                nome_tabela_atual = tabela_match.group(1)
                linhas_corpo_tabela = []
            continue

        # 3. Se está dentro da tabela, acumula ou detecta o fim do bloco
        if dentro_de_tabela:
            # Se a linha fecha o parêntese do CREATE TABLE (ex: ) ENGINE=InnoDB... ou );)
            if linha_limpa.startswith(")") or (linha_limpa.endswith(";") and not linha_limpa.startswith("`") and "(" not in linha_limpa):
                # PROCESSA A TABELA ACUMULADA
                corpo_completo = "\n".join(linhas_corpo_tabela)
                
                mapeamento_colunas = {}
                chaves_primarias = []

                # Extração de PRIMARY KEY explícita
                pk_inline_matches = re.findall(r"PRIMARY\s+KEY\s*\((.*?)\)", corpo_completo, re.IGNORECASE)
                for pk_match in pk_inline_matches:
                    for col_pk in re.split(r",", pk_match):
                        chaves_primarias.append(col_pk.replace("`", "").replace("'", "").replace('"', "").strip())

                # Fallback para UNIQUE KEY se não achar PK
                if not chaves_primarias:
                    unique_matches = re.findall(r"UNIQUE\s+KEY\s+(?:[`'\"\w\-]+\s+)?\((.*?)\)", corpo_completo, re.IGNORECASE)
                    if unique_matches:
                        for col_uk in re.split(r",", unique_matches[0]):
                            chaves_primarias.append(col_uk.replace("`", "").replace("'", "").replace('"', "").strip())

                # Processa cada linha de coluna guardada
                for l_col in linhas_corpo_tabela:
                    l_col = l_col.strip()
                    if l_col.endswith(","):
                        l_col = l_col[:-1].strip()
                        
                    if not l_col or l_col.upper().startswith(("PRIMARY KEY", "KEY", "CONSTRAINT", "UNIQUE", "INDEX", "FULLTEXT", "SPATIAL")):
                        continue

                    col_match = re.match(r"^--*|^\/\*", l_col) # ignora comentários inline
                    if col_match:
                        continue

                    col_match = re.match(r"^[`'\"\[]?([a-zA-Z0-9_\-]+)[`'\"\]]?\s+(.+)$", l_col, re.IGNORECASE)
                    if col_match:
                        col_nome = col_match.group(1)
                        resto_linha = col_match.group(2).strip()
                        
                        if col_nome.upper() in ["KEY", "PRIMARY", "UNIQUE", "INDEX", "CONSTRAINT"]:
                            continue

                        tipo_match = re.match(r"^([a-zA-Z0-9_]+(?:\s*\([^)]+\))?)", resto_linha)
                        col_tipo = tipo_match.group(1).upper() if tipo_match else "VARCHAR(255)"
                        
                        is_pk = col_nome in chaves_primarias or "PRIMARY KEY" in l_col.upper()

                        mapeamento_colunas[col_nome] = {
                            "nome_destino": col_nome,
                            "tipo": col_tipo,
                            "pk": True if is_pk else None
                        }
                        if mapeamento_colunas[col_nome]["pk"] is None:
                            del mapeamento_colunas[col_nome]["pk"]

                # Identificação da coluna de timestamp/controle
                coluna_controle = None
                termos_controle = ["updated", "modified", "timestamp", "created_at", "date", "created"]
                for termo in termos_controle:
                    for col_original in mapeamento_colunas.keys():
                        if termo in col_original.lower():
                            coluna_controle = col_original
                            break
                    if coluna_controle:
                        break

                # Monta a estrutura JSON da tabela
                tabela_json = {
                    "tabela_legada": nome_tabela_atual,
                    "tabela_moderna": nome_tabela_atual,
                    "schema_legado": None,
                    "schema_moderno": None,
                    "coluna_last_updated": coluna_controle,
                    "intervalo_sincronizacao_segundos": 1,
                    "camada_anticorrupcao": {
                        "sanitizacao": {
                            "remover_espacos_excesso": REMOVER_ESPACOS_PADRAO,
                            "forcar_utf8": FORCAR_UTF8_PADRAO
                        },
                        "mapeamento_colunas": mapeamento_colunas
                    }
                }
                lista_tabelas_mapeadas.append(tabela_json)
                
                # Reseta o estado para a próxima tabela
                dentro_de_tabela = False
                nome_tabela_atual = None
                linhas_corpo_tabela = []
            else:
                # Se ainda está dentro do bloco, vai guardando as linhas do corpo
                linhas_corpo_tabela.append(linha)

    return nome_banco_legado, lista_tabelas_mapeadas

# ==============================================================================
# EXECUÇÃO PRINCIPAL
# ==============================================================================
def gerar_pipeline_config_literal():
    nome_banco, tabelas_processadas = extrair_bancos_e_tabelas_literal(ARQUIVO_SQL_ENTRADA)

    pipeline_config = {
        "configuracao_infraestrutura": {
            "intervalo_verificacao_segundos": 1,
            "intervalo_limpeza_orfaos_minutos": 5,
            "origem_command": {
                "sgbd": "env:DB_ORIGEM_SGBD",
                "host": "env:DB_ORIGEM_HOST",
                "usuario": "env:DB_ORIGEM_USER",
                "senha": "env:DB_ORIGEM_PASS",
                "porta": "env:DB_ORIGEM_PORT"
            },
            "destino_query": {
                "sgbd": "env:DB_DESTINO_SGBD",
                "host": "env:DB_DESTINO_HOST",
                "usuario": "env:DB_DESTINO_USER",
                "senha": "env:DB_DESTINO_PASS",
                "porta": "env:DB_DESTINO_PORT"
            }
        },
        "bancos_gerenciados": [
            {
                "banco_legado": nome_banco,
                "banco_moderno": nome_banco,
                "tabelas": tabelas_processadas
            }
        ]
    }

    with open(ARQUIVO_JSON_SAIDA, "w", encoding="utf-8") as f:
        json.dump(pipeline_config, f, indent=4, ensure_ascii=False)
        
    print(f"[SUCESSO] Configuração gerada via varredura linear para {len(tabelas_processadas)} tabelas!")
    print(f"[INFO] Arquivo salvo em: '{ARQUIVO_JSON_SAIDA}'")

if __name__ == "__main__":
    gerar_pipeline_config_literal()