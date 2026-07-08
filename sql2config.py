import json
import re

# ==============================================================================
# CONFIGURAÇÕES DE ENTRADA E SAÍDA
# ==============================================================================
ARQUIVO_SQL_ENTRADA = "est_wp_scinfor.sql"  # Altere o nome do arquivo aqui
ARQUIVO_JSON_SAIDA = "pipeline_config_gerado.json"

# ==============================================================================
# MOTOR DE EXTRAÇÃO PARSER SQL (LITERAL E GENÉRICO)
# ==============================================================================
def extrair_bancos_e_tabelas_literal(caminho_sql):
    with open(caminho_sql, "r", encoding="utf-8", errors="ignore") as f:
        conteudo = f.read()

    # Captura o nome exato do banco de dados (preservando maiúsculas/minúsculas)
    banco_match = re.search(r"CREATE\s+DATABASE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`'\"\[]?([a-zA-Z0-9_\-]+)[`'\"\]]?", conteudo, re.IGNORECASE)
    nome_banco_legado = banco_match.group(1) if banco_match else "banco_legado_detectado"
    
    # Captura as tabelas e o corpo dos comandos CREATE TABLE
    tabelas_raw = re.findall(r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`'\"\[]?([a-zA-Z0-9_\-]+)[`'\"\]]?\s*\((.*?)\)\s*(?:ENGINE|DEFAULT|CHARSET|PRIMARY|\n|;)", conteudo, re.DOTALL | re.IGNORECASE)
    
    lista_tabelas_mapeadas = []

    for nome_tabela, corpo_tabela in tabelas_raw:
        mapeamento_colunas = {}
        chaves_primarias = []

        # Extração de Chave Primária declarada ao final (PRIMARY KEY)
        pk_inline_matches = re.findall(r"PRIMARY\s+KEY\s*\((.*?)\)", corpo_tabela, re.IGNORECASE)
        for pk_match in pk_inline_matches:
            for col_pk in re.split(r",", pk_match):
                chaves_primarias.append(col_pk.replace("`", "").replace("'", "").replace('"', "").replace("[", "").replace("]", "").strip())

        # Processamento das Linhas do Corpo da Tabela
        linhas = corpo_tabela.split("\n")
        for linha in linhas:
            linha = linha.strip()
            if not linha or linha.upper().startswith(("PRIMARY KEY", "KEY", "CONSTRAINT", "UNIQUE", "INDEX")):
                continue
                
            # Captura exata do nome da coluna (mantendo case original) e o tipo de dado puro
            col_match = re.match(r"^[`'\"\[]?([a-zA-Z0-9_\-]+)[`'\"\]]?\s+([a-zA-Z]+(?:\s*\(\s*\d+\s*(?:\s*,\s*\d+)?\s*\))?)", linha, re.IGNORECASE)
            if col_match:
                col_nome = col_match.group(1)
                col_tipo = col_match.group(2).upper()
                
                # CÓPIA IDENTICA: O nome de destino passa a ser idêntico ao nome original da coluna
                nome_destino = col_nome 

                # Identifica se a linha é chave primária
                is_pk = col_nome in chaves_primarias or "PRIMARY KEY" in linha.upper()

                mapeamento_colunas[col_nome] = {
                    "nome_destino": nome_destino,
                    "tipo": col_tipo, # Mantém o tipo original extraído do SQL
                    "pk": True if is_pk else None
                }
                
                if mapeamento_colunas[col_nome]["pk"] is None:
                    del mapeamento_colunas[col_nome]["pk"]

        # Busca literal por campos de timestamp cronológicos para o controle interno
        coluna_controle = None
        for col_original in mapeamento_colunas.keys():
            if col_original.lower() in ["created_at", "updated_at", "dt_alteracao", "form_date", "comment_date", "post_date", "post_modified"]:
                coluna_controle = col_original
                break

        # Montagem estrutural sem nenhuma modificação de nomes
        tabela_json = {
            "tabela_legada": nome_tabela,       # Nome original (ex: ifsc_users)
            "tabela_moderna": nome_tabela,      # CÓPIA EXATA (ex: ifsc_users)
            "schema_legado": None,
            "schema_moderno": None,
            "coluna_timestamp_controle": coluna_controle,
            "coluna_last_updated": coluna_controle, # Aponta para o mesmo nome original
            "intervalo_sincronizacao_segundos": 1,
            "camada_anticorrupcao": {
                "sanitizacao": {
                    "remover_espacos_excesso": False, # Desativado conforme solicitado
                    "forcar_utf8": True
                },
                "mapeamento_colunas": mapeamento_colunas
            }
        }
        lista_tabelas_mapeadas.append(tabela_json)

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
                "banco_legado": nome_banco,   # Cópia literal (ex: scinforwp)
                "banco_moderno": nome_banco,  # Cópia literal (ex: scinforwp)
                "tabelas": tabelas_processadas
            }
        ]
    }

    with open(ARQUIVO_JSON_SAIDA, "w", encoding="utf-8") as f:
        json.dump(pipeline_config, f, indent=4, ensure_ascii=False)
        
    print(f"[SUCESSO] Configuração gerada com cópia 1:1 literal!")
    print(f"[INFO] Arquivo salvo em: '{ARQUIVO_JSON_SAIDA}'")

if __name__ == "__main__":
    gerar_pipeline_config_literal()