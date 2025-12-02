import pandas as pd
import pyodbc
import json
import sys
import os
import re

# Configuração para garantir serialização correta do JSON
class NpEncoder(json.JSONEncoder):
    def default(self, obj):
        if isinstance(obj, pd.np.integer): return int(obj)
        if isinstance(obj, pd.np.floating): return float(obj)
        if isinstance(obj, pd.np.ndarray): return obj.tolist()
        return super(NpEncoder, self).default(obj)

if len(sys.argv) < 2:
    print(json.dumps({"erro": "Nenhum arquivo fornecido."}))
    sys.exit(1)

arquivo_input = sys.argv[1]
arquivo_audit = sys.argv[2] if len(sys.argv) > 2 else None

nome_base, _ = os.path.splitext(arquivo_input)
arquivo_output = f"{nome_base}_processado.csv"

# --- FUNÇÕES AUXILIARES ---
def limpar_valor(valor):
    """Normaliza valores monetários no formato BRL para float."""
    if isinstance(valor, str):
        clean = valor.replace("R$", "").replace(" ", "").replace(".", "")
        clean = clean.replace(",", ".")
        try:
            return float(clean)
        except ValueError:
            return 0.0
    elif isinstance(valor, (int, float)):
        return float(valor)
    return 0.0

def extrair_id_canal(texto):
    """Extrai ID do canal de strings como '14 - Especializado'"""
    if pd.isna(texto): return "99" 
    texto = str(texto).strip()
    match = re.match(r"(\d+)", texto)
    if match:
        return match.group(1)
    return "99"

# --- 1. CARREGAMENTO E LIMPEZA (ETL) ---
try:
    df = pd.read_excel(arquivo_input, engine='openpyxl')
    
    # Seleção de colunas relevantes (ajuste conforme seu Excel real)
    colunas_manter = [
        'Tipo', 'Numero da Nota', 'Data', 'Cliente', 'Org Vendas', 
        'Canal', 'Valor Liquido', 'Vendedor' 
    ]
    # Filtra colunas existentes para evitar erros
    cols_existentes = [c for c in colunas_manter if c in df.columns]
    df = df[cols_existentes]

    # Regra de Negócio: Apenas operações de VENDA são contabilizadas
    if 'Tipo' in df.columns:
        df = df[df['Tipo'].astype(str).str.strip().str.upper() == 'VENDA']

    # Tratamento de Tipos
    if 'Data' in df.columns:
        df['Data'] = pd.to_datetime(df['Data'], errors='coerce').dt.strftime('%d/%m/%Y')

    if 'Cliente' in df.columns:
        df['Cliente'] = df['Cliente'].astype(str).apply(lambda x: x.split('.')[0].lstrip('0'))
        
    if 'Valor Liquido' in df.columns:
        df['Valor Liquido'] = df['Valor Liquido'].apply(limpar_valor)

    # Identificação do Canal via Planilha
    if 'Canal' in df.columns:
        df['FAD_ID_Planilha'] = df['Canal'].apply(extrair_id_canal)
    else:
        df['FAD_ID_Planilha'] = "99"

except Exception as e:
    print(json.dumps({"erro": f"Erro ao ler arquivo base: {str(e)}"}))
    sys.exit(1)

# --- 2. INTEGRAÇÃO COM BANCO DE DADOS (SQL SERVER) ---
try:
    # Em produção, utilize variáveis de ambiente
    conn_str = (
        "DRIVER={ODBC Driver 17 for SQL Server};"
        f"SERVER={os.getenv('DB_SERVER', 'SERVIDOR_MOCK')};"
        f"DATABASE={os.getenv('DB_NAME', 'DB_MOCK')};"
        f"UID={os.getenv('DB_USER', 'USER')};"
        f"PWD={os.getenv('DB_PASS', 'PASS')};"
    )
    
    try:
        conn = pyodbc.connect(conn_str)
        
        # Consulta sanitizada
        query = """
        SELECT DISTINCT 
            CAST(C.COD_CLIENTE AS VARCHAR) AS 'Cliente',
            C.COD_FAD AS 'FAD_ID_Banco',
            F.DESC_FAD AS 'FAD_Desc_Banco',
            V.COD_VENDEDOR AS 'Vendedor'
        FROM ERP.dbo.TBL_CLIENTES AS C
        JOIN ERP.dbo.TBL_FAD AS F ON C.COD_FAD = F.COD_FAD
        JOIN ERP.dbo.TBL_VENDEDORES AS V ON C.COD_VENDEDOR = V.COD_VENDEDOR;
        """
        
        df_sql = pd.read_sql(query, conn)
        conn.close()
        
        df_sql['Cliente'] = df_sql['Cliente'].apply(lambda x: str(x).split('.')[0].lstrip('0'))
        df_completo = pd.merge(df, df_sql, on='Cliente', how='left')
        
    except Exception as db_err:
        # Fallback se não houver conexão (para portfolio rodar sem banco)
        df_completo = df.copy()
        df_completo['FAD_ID_Banco'] = '99'
        df_completo['FAD_Desc_Banco'] = 'Sem Conexão SQL'

except Exception as e:
    print(json.dumps({"erro": f"Erro Crítico no SQL: {str(e)}"}))
    sys.exit(1)

# --- 3. CARREGAR REGRAS DE NEGÓCIO (JSON) ---
try:
    dir_atual = os.path.dirname(os.path.abspath(__file__))
    json_path = os.path.join(dir_atual, 'config_fads.json')
    with open(json_path, 'r') as f:
        regras = json.load(f)
except Exception as e:
    print(json.dumps({"erro": f"Erro Configuração: {str(e)}"}))
    sys.exit(1)

# --- 4. MOTOR DE CÁLCULO (DUPLO CENÁRIO) ---
try:
    # Agrupamento: Drop = Cliente + Data
    df_agrupado_valores = df_completo.groupby(['Cliente', 'Data'])['Valor Liquido'].sum().reset_index()
    df_agrupado_valores.rename(columns={'Valor Liquido': 'Total_Dia'}, inplace=True)
    
    cols_cadastrais = ['Cliente', 'Data', 'Vendedor', 'FAD_ID_Planilha', 'FAD_ID_Banco', 'FAD_Desc_Banco', 'Canal']
    # Filtra colunas existentes
    cols_existentes = [c for c in cols_cadastrais if c in df_completo.columns]
    
    df_cadastral = df_completo.drop_duplicates(subset=['Cliente', 'Data'])[cols_existentes]
    
    df_analise = pd.merge(df_cadastral, df_agrupado_valores, on=['Cliente', 'Data'], how='left')

    def check_drop(fad_id, total):
        fad_str = str(fad_id).split('.')[0]
        if fad_str not in regras:
            return False, 0.0, f"Sem Regra ({fad_id})"
        minimo = regras[fad_str]['minimo']
        desc = regras[fad_str]['descricao']
        return (total >= minimo), minimo, desc

    # Cenário 1: Planilha
    res_plan = df_analise.apply(lambda x: check_drop(x.get('FAD_ID_Planilha'), x['Total_Dia']), axis=1, result_type='expand')
    df_analise['Valido_Planilha'] = res_plan[0]
    df_analise['Minimo_Planilha'] = res_plan[1]
    df_analise['Desc_FAD_Planilha'] = res_plan[2]

    # Cenário 2: Banco
    res_banco = df_analise.apply(lambda x: check_drop(x.get('FAD_ID_Banco'), x['Total_Dia']), axis=1, result_type='expand')
    df_analise['Valido_Banco'] = res_banco[0]
    df_analise['Minimo_Banco'] = res_banco[1]
    df_analise['Desc_FAD_Banco'] = df_analise.get('FAD_Desc_Banco', pd.Series(['N/D']*len(df_analise))).fillna('N/D')

    df_analise.to_csv(arquivo_output, index=False, sep=';', encoding='utf-8-sig')

    if 'Vendedor' in df_analise.columns:
        df_analise['Vendedor'] = df_analise['Vendedor'].fillna(0).astype(int).astype(str)
        df_analise.loc[df_analise['Vendedor'] == '0', 'Vendedor'] = 'N/D'

    # --- 5. ESTATÍSTICAS ---
    def gerar_resumo(df_base, col_validacao, col_fad_nome):
        df_val = df_base[df_base[col_validacao] == True].copy()
        return {
            "total": len(df_val),
            "por_vendedor": df_val['Vendedor'].value_counts().to_dict(),
            "por_sold": df_val['Cliente'].value_counts().to_dict(),
            "por_fad": df_val[col_fad_nome].value_counts().to_dict()
        }

    cenario_planilha = gerar_resumo(df_analise, 'Valido_Planilha', 'Desc_FAD_Planilha')
    cenario_banco = gerar_resumo(df_analise, 'Valido_Banco', 'Desc_FAD_Banco')

    # --- 6. AUDITORIA FINANCEIRA (GABARITO) ---
    audit_data = {}
    has_audit = False
    
    if arquivo_audit and os.path.exists(arquivo_audit):
        try:
            df_aud = pd.read_excel(arquivo_audit, engine='openpyxl')
            col_canal = next((c for c in df_aud.columns if 'canal' in c.lower() or 'fad' in c.lower()), None)
            col_qtd = next((c for c in df_aud.columns if 'qtd' in c.lower() or 'drop' in c.lower()), None)
            
            if col_canal and col_qtd:
                has_audit = True
                def limpar_canal_audit(val):
                    s = str(val).strip()
                    if ' - ' in s: return s.split(' - ', 1)[1].strip()
                    return s
                df_aud['Canal_Limpo'] = df_aud[col_canal].apply(limpar_canal_audit)
                audit_data = df_aud.groupby('Canal_Limpo')[col_qtd].sum().to_dict()
        except: pass

    def montar_audit_list(resumo_fad_dict):
        lista = []
        norm_audit = {k.strip().lower(): v for k,v in audit_data.items()}
        for fad, calc in resumo_fad_dict.items():
            k_norm = str(fad).strip().lower()
            esp = 0
            for ak, av in norm_audit.items():
                if ak in k_norm or k_norm in ak: 
                    esp = av
                    break
            diff = calc - esp
            lista.append({"fad": fad, "calculado": calc, "esperado": esp, "diff": diff})
        return lista

    # --- 7. AUDITORIA CADASTRAL ---
    def normalize_fad(txt): return str(txt).strip().lower()
    
    # Compara descrições normalizadas
    df_analise['Div_Cadastral'] = df_analise.apply(
        lambda x: normalize_fad(x['Desc_FAD_Planilha']) != normalize_fad(x['Desc_FAD_Banco']), 
        axis=1
    )
    
    # Lista única de clientes divergentes
    df_div = df_analise[df_analise['Div_Cadastral'] == True].drop_duplicates(subset=['Cliente'])
    
    # Garante valores padrão se coluna não existir
    for col in ['FAD_ID_Planilha', 'FAD_ID_Banco', 'Desc_FAD_Planilha', 'Desc_FAD_Banco']:
        if col not in df_div.columns: df_div[col] = '?'

    lista_divergencia_cadastral = df_div[[
        'Cliente', 'Vendedor', 
        'FAD_ID_Planilha', 'Desc_FAD_Planilha', 
        'FAD_ID_Banco', 'Desc_FAD_Banco'
    ]].fillna('?').to_dict('records')

    # Dados Detalhados
    cols_detalhe = {
        'Data': 'data', 'Cliente': 'cliente', 'Vendedor': 'vendedor', 'Total_Dia': 'valor',
        'Desc_FAD_Planilha': 'fad_plan', 'Minimo_Planilha': 'min_plan', 'Valido_Planilha': 'valid_plan',
        'Desc_FAD_Banco': 'fad_banc', 'Minimo_Banco': 'min_banc', 'Valido_Banco': 'valid_banc',
        'Div_Cadastral': 'is_divergent'
    }
    # Filtra colunas válidas
    valid_cols = [c for c in cols_detalhe.keys() if c in df_analise.columns]
    df_detalhes = df_analise[valid_cols].rename(columns=cols_detalhe)
    detalhes_list = df_detalhes.to_dict('records')

    output = {
        "sucesso": True,
        "arquivo": arquivo_output,
        "cenario_planilha": {
            "stats": cenario_planilha,
            "audit": montar_audit_list(cenario_planilha['por_fad'])
        },
        "cenario_banco": {
            "stats": cenario_banco,
            "audit": montar_audit_list(cenario_banco['por_fad'])
        },
        "detalhes": detalhes_list,
        "lista_divergencia_cadastral": lista_divergencia_cadastral,
        "tem_audit_file": has_audit
    }
    
    print(json.dumps(output, cls=NpEncoder))

except Exception as e:
    print(json.dumps({"erro": f"Erro Geral: {str(e)}"}))
    sys.exit(1)