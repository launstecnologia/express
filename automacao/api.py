# api.py
# API REST para automação PagBank — Força de Vendas
# Expõe endpoints para o Laravel acionar e acompanhar os jobs de automação.
#
# Iniciar:  uvicorn api:app --host 0.0.0.0 --port 8001 --workers 1
# Prod:     gunicorn api:app -k uvicorn.workers.UvicornWorker --bind 0.0.0.0:8001 --workers 1

import json
import logging
import os
import sqlite3
import threading
import uuid
from datetime import datetime

from dotenv import load_dotenv
from fastapi import FastAPI, Header, HTTPException
from fastapi.responses import FileResponse, JSONResponse
from pydantic import BaseModel, Field

load_dotenv()

log = logging.getLogger('automacao_api')
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%H:%M:%S',
)

API_KEY       = os.getenv('AUTOMACAO_API_KEY', 'troque-me')
DB_PATH       = os.getenv('AUTOMACAO_DB_PATH', '/tmp/automacao_jobs.db')
SCREENSHOT_DIR = os.getenv('AUTOMACAO_SCREENSHOT_DIR', '/tmp/automacao_screenshots')

app = FastAPI(
    title='Automação PagBank FV',
    description='API para cadastro automatizado na Força de Vendas PagBank via Selenium.',
    version='1.0.0',
    docs_url='/docs',
)

# ----------------------------------------------------------------
# Banco SQLite simples para persistência dos jobs
# ----------------------------------------------------------------
def _get_conn() -> sqlite3.Connection:
    conn = sqlite3.connect(DB_PATH, check_same_thread=False)
    conn.row_factory = sqlite3.Row
    return conn


def _init_db() -> None:
    with _get_conn() as conn:
        conn.execute('''
            CREATE TABLE IF NOT EXISTS jobs (
                id                  TEXT PRIMARY KEY,
                estabelecimento_id  INTEGER,
                status              TEXT NOT NULL DEFAULT "pendente",
                etapa_atual         TEXT,
                dados               TEXT,
                resultado           TEXT,
                erro                TEXT,
                criado_em           TEXT NOT NULL,
                atualizado_em       TEXT NOT NULL
            )
        ''')
        try:
            conn.execute('ALTER TABLE jobs ADD COLUMN etapa_atual TEXT')
        except sqlite3.OperationalError:
            pass
        conn.execute('''
            CREATE TABLE IF NOT EXISTS job_logs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id      TEXT NOT NULL,
                nivel       TEXT NOT NULL DEFAULT 'info',
                etapa       TEXT,
                mensagem    TEXT NOT NULL,
                detalhe     TEXT,
                criado_em   TEXT NOT NULL
            )
        ''')
        conn.execute(
            'CREATE INDEX IF NOT EXISTS idx_job_logs_job_id ON job_logs(job_id, id)'
        )
        conn.commit()


_init_db()
_db_lock = threading.Lock()


def _append_job_log(
    job_id: str,
    mensagem: str,
    nivel: str = 'info',
    etapa: str | None = None,
    detalhe: dict | None = None,
) -> int:
    agora = datetime.now().isoformat()
    with _db_lock:
        with _get_conn() as conn:
            cur = conn.execute(
                'INSERT INTO job_logs (job_id, nivel, etapa, mensagem, detalhe, criado_em)'
                ' VALUES (?, ?, ?, ?, ?, ?)',
                (
                    job_id,
                    nivel,
                    etapa,
                    mensagem,
                    json.dumps(detalhe, ensure_ascii=False) if detalhe else None,
                    agora,
                ),
            )
            conn.commit()
            return int(cur.lastrowid)


def _get_job_logs(job_id: str) -> list[dict]:
    with _get_conn() as conn:
        rows = conn.execute(
            'SELECT id, job_id, nivel, etapa, mensagem, detalhe, criado_em'
            ' FROM job_logs WHERE job_id=? ORDER BY id ASC',
            (job_id,),
        ).fetchall()

    logs = []
    for row in rows:
        detalhe = None
        if row['detalhe']:
            try:
                detalhe = json.loads(row['detalhe'])
            except json.JSONDecodeError:
                detalhe = {'raw': row['detalhe']}
        logs.append({
            'id': row['id'],
            'job_id': row['job_id'],
            'nivel': row['nivel'],
            'etapa': row['etapa'],
            'mensagem': row['mensagem'],
            'detalhe': detalhe,
            'criado_em': row['criado_em'],
        })
    return logs


def _update_job(job_id: str, status: str,
                resultado: dict | None = None,
                erro: str | None = None,
                etapa_atual: str | None = None) -> None:
    if erro:
        _append_job_log(
            job_id,
            erro,
            nivel='erro',
            etapa=etapa_atual,
            detalhe={'status': status, 'resultado': resultado} if resultado else {'status': status},
        )
    elif status == 'concluido':
        _append_job_log(
            job_id,
            etapa_atual or 'Job concluído com sucesso',
            nivel='sucesso',
            etapa=etapa_atual or 'Concluído',
        )

    with _db_lock:
        with _get_conn() as conn:
            if etapa_atual is not None:
                conn.execute(
                    'UPDATE jobs SET status=?, resultado=?, erro=?, etapa_atual=?, atualizado_em=? WHERE id=?',
                    (
                        status,
                        json.dumps(resultado, ensure_ascii=False) if resultado else None,
                        erro,
                        etapa_atual,
                        datetime.now().isoformat(),
                        job_id,
                    ),
                )
            else:
                conn.execute(
                    'UPDATE jobs SET status=?, resultado=?, erro=?, atualizado_em=? WHERE id=?',
                    (
                        status,
                        json.dumps(resultado, ensure_ascii=False) if resultado else None,
                        erro,
                        datetime.now().isoformat(),
                        job_id,
                    ),
                )
            conn.commit()


def _set_etapa(job_id: str, etapa: str) -> None:
    _append_job_log(job_id, etapa, nivel='info', etapa=etapa)
    with _db_lock:
        with _get_conn() as conn:
            conn.execute(
                'UPDATE jobs SET etapa_atual=?, atualizado_em=? WHERE id=?',
                (etapa, datetime.now().isoformat(), job_id),
            )
            conn.commit()
    log.info(f'[{job_id}] Etapa: {etapa}')


def _screenshot_dir(job_id: str) -> str:
    return os.path.join(SCREENSHOT_DIR, job_id)


def _resumir_erro_selenium(erro: str) -> str:
    if not erro:
        return 'Erro desconhecido no cadastro FV'
    if 'PagBank não avançou' in erro:
        return erro.split('Stacktrace:')[0].strip()
    for linha in erro.split('\n'):
        linha = linha.strip()
        if not linha or linha == 'Message:' or linha.startswith('#'):
            continue
        if linha.startswith('Message:'):
            resto = linha[8:].strip()
            if resto:
                return resto
        return linha
    return erro[:400]


def _formatar_erro_fv(fv_resultado: dict) -> str:
    etapa = fv_resultado.get('etapa_falha_label') or 'Cadastro Força de Vendas'
    resumo = fv_resultado.get('erro_resumido') or _resumir_erro_selenium(
        fv_resultado.get('erro', 'Erro desconhecido no cadastro FV')
    )
    return f'Falha na etapa "{etapa}": {resumo}'


def _resolver_arquivo_screenshot(job_id: str, filename: str) -> str:
    from urllib.parse import unquote

    nome = os.path.basename(unquote(filename))
    if '..' in nome or not nome.endswith('.png'):
        raise HTTPException(status_code=404, detail='Screenshot não encontrado')

    caminho = os.path.join(_screenshot_dir(job_id), nome)
    if not os.path.isfile(caminho):
        raise HTTPException(status_code=404, detail='Screenshot não encontrado')

    return caminho


def _executar_etapa_proposta(job_id: str, req: dict, screenshot_dir: str) -> dict:
    from aceitar_proposta import aceitar_proposta

    documento = req.get('documento') or req.get('dados', {}).get('cpf_cnpj', '')
    email = req.get('email') or req.get('webmail_usuario') or req.get('dados', {}).get('email', '')
    senha_6 = req.get('senha_6', '')

    _set_etapa(job_id, 'Aceitando proposta comercial no PagBank...')
    log.info(f'[{job_id}] Aceitar proposta: documento={documento}')

    return aceitar_proposta(
        documento=documento,
        senha_6=senha_6,
        email=email,
        email_suffix=req.get('email_suffix', 'express.app.br'),
        headless=req.get('headless', True),
        screenshot_dir=screenshot_dir,
        job_id=job_id,
    )


# ----------------------------------------------------------------
# Schemas
# ----------------------------------------------------------------
class CadastrarRequest(BaseModel):
    estabelecimento_id: int = Field(..., description='ID do estabelecimento no Laravel')
    dados: dict = Field(..., description='Dados do estabelecimento para a Força de Vendas')
    fv_usuario: str = Field(..., description='Usuário de acesso ao portal FV PagBank')
    fv_senha: str = Field(..., description='Senha do portal FV PagBank')
    webmail_url: str = Field(..., description='URL do Roundcube (ex: https://mail.seudominio.com.br)')
    webmail_usuario: str = Field(..., description='Email para login no webmail')
    webmail_senha: str = Field(..., description='Senha do webmail')
    senha_6: str = Field(..., min_length=6, max_length=6, description='Senha 6 dígitos para conta PagBank')
    headless: bool = Field(default=True, description='Rodar Chrome em modo headless')
    aguardar_email_seg: int = Field(default=90, description='Segundos para aguardar o email de confirmação')


class ConsultarDocumentoRequest(BaseModel):
    documento: str = Field(..., min_length=11, max_length=18, description='CPF ou CNPJ')
    fv_usuario: str = Field(..., description='Usuário de acesso ao portal FV PagBank')
    fv_senha: str = Field(..., description='Senha do portal FV PagBank')
    headless: bool = Field(default=True, description='Rodar Chrome em modo headless')


class BuscarSafepayRequest(BaseModel):
    estabelecimento_id: int = Field(..., description='ID do estabelecimento no Laravel')
    documento: str = Field(..., min_length=11, max_length=18, description='CPF ou CNPJ')
    fv_usuario: str = Field(..., description='Usuário de acesso ao portal FV PagBank')
    fv_senha: str = Field(..., description='Senha do portal FV PagBank')
    email_suffix: str = Field(default='express.app.br', description='Domínio do e-mail a localizar')
    headless: bool = Field(default=True, description='Rodar Chrome em modo headless')


class RetentarEmailRequest(BaseModel):
    estabelecimento_id: int = Field(..., description='ID do estabelecimento no Laravel')
    documento: str = Field(default='', description='CPF ou CNPJ para aceite de proposta após senha')
    webmail_url: str = Field(..., description='URL do Roundcube')
    webmail_usuario: str = Field(..., description='Email para login no webmail')
    webmail_senha: str = Field(..., description='Senha do webmail')
    senha_6: str = Field(..., min_length=6, max_length=6, description='Senha 6 dígitos para conta PagBank')
    headless: bool = Field(default=True)
    aguardar_email_seg: int = Field(default=90)


class AceitarPropostaRequest(BaseModel):
    estabelecimento_id: int = Field(..., description='ID do estabelecimento no Laravel')
    documento: str = Field(..., min_length=11, max_length=18, description='CPF ou CNPJ')
    senha_6: str = Field(..., min_length=6, max_length=6, description='Senha 6 dígitos da conta PagBank')
    email: str = Field(default='', description='E-mail @express.app.br do cliente')
    email_suffix: str = Field(default='express.app.br', description='Domínio do e-mail a selecionar')
    headless: bool = Field(default=True, description='Rodar Chrome em modo headless')


# ----------------------------------------------------------------
# Autenticação
# ----------------------------------------------------------------
def _autenticar(x_api_key: str) -> None:
    if x_api_key != API_KEY:
        raise HTTPException(status_code=401, detail='Chave de API inválida')


# ----------------------------------------------------------------
# Lógica de execução (roda em thread separada)
# ----------------------------------------------------------------
def _executar_somente_email(job_id: str, req: dict) -> None:
    from progresso import registrar, remover

    screenshot_dir = os.path.join(SCREENSHOT_DIR, job_id)
    os.makedirs(screenshot_dir, exist_ok=True)

    registrar(job_id, lambda etapa: _set_etapa(job_id, etapa))

    try:
        _append_job_log(job_id, 'Execução iniciada', 'info', 'Início')
        _update_job(job_id, 'em_andamento', etapa_atual='Retentando etapa de e-mail...')
        log.info(f'[{job_id}] Retentando apenas email para estab {req["estabelecimento_id"]}')
        log.info(f'[{job_id}] webmail_url={req.get("webmail_url")}')

        from validacao_email import validar_email

        email_resultado = validar_email(
            webmail_url=req['webmail_url'],
            webmail_usuario=req['webmail_usuario'],
            webmail_senha=req['webmail_senha'],
            senha_6=req['senha_6'],
            headless=req.get('headless', True),
            screenshot_dir=screenshot_dir,
            aguardar_email_seg=req.get('aguardar_email_seg', 90),
            job_id=job_id,
        )

        resultado_final = {
            'etapa_fv': {'sucesso': True, 'info': 'Cadastro FV já concluído anteriormente'},
            'etapa_email': email_resultado,
            'senha_6': req['senha_6'],
        }

        if email_resultado.get('sucesso'):
            _update_job(job_id, 'concluido', resultado=resultado_final, etapa_atual='Concluído com sucesso')
            log.info(f'[{job_id}] E-mail concluído com sucesso (proposta comercial fica para ação manual)')
        else:
            _update_job(
                job_id, 'erro_email',
                resultado=resultado_final,
                erro=email_resultado.get('erro', 'Erro na validação de email'),
                etapa_atual='Erro na etapa de e-mail',
            )

    except Exception as e:
        log.exception(f'[{job_id}] Erro inesperado no retentar email')
        _update_job(job_id, 'erro_email', erro=str(e), etapa_atual='Erro inesperado')
    finally:
        remover(job_id)


def _executar_busca_safepay(job_id: str, req: dict) -> None:
    from progresso import registrar, remover

    screenshot_dir = os.path.join(SCREENSHOT_DIR, job_id)
    os.makedirs(screenshot_dir, exist_ok=True)

    registrar(job_id, lambda etapa: _set_etapa(job_id, etapa))

    try:
        _append_job_log(job_id, 'Execução iniciada', 'info', 'Início')
        _update_job(job_id, 'em_andamento', etapa_atual='Buscando Safepay ID no PagBank...')
        log.info(f'[{job_id}] Busca Safepay ID: {req.get("documento")}')

        from main import buscar_safepay_id_fv

        email_suffix = req.get('email_suffix') or 'express.app.br'
        if not email_suffix.startswith('@'):
            email_suffix = f'@{email_suffix}'

        resultado = buscar_safepay_id_fv(
            documento=req['documento'],
            fv_usuario=req['fv_usuario'],
            fv_senha=req['fv_senha'],
            email_suffix=email_suffix,
            headless=req.get('headless', True),
            screenshot_dir=screenshot_dir,
            job_id=job_id,
        )

        if not resultado.get('sucesso'):
            _update_job(
                job_id, 'erro',
                resultado={'tipo_job': 'busca_safepay', 'detalhe': resultado},
                erro=resultado.get('erro', 'Safepay ID não encontrado'),
                etapa_atual='Safepay ID não encontrado',
            )
            return

        _update_job(
            job_id, 'concluido',
            resultado={'tipo_job': 'busca_safepay', 'detalhe': resultado, 'safepay_id': resultado.get('safepay_id')},
            etapa_atual='Safepay ID encontrado',
        )
        log.info(f'[{job_id}] Safepay ID: {resultado.get("safepay_id")}')

    except Exception as e:
        log.exception(f'[{job_id}] Erro inesperado na busca Safepay')
        _update_job(job_id, 'erro', erro=str(e), etapa_atual='Erro inesperado')
    finally:
        remover(job_id)


def _executar_consulta_documento(job_id: str, req: dict) -> None:
    from progresso import registrar, remover

    screenshot_dir = os.path.join(SCREENSHOT_DIR, job_id)
    os.makedirs(screenshot_dir, exist_ok=True)

    registrar(job_id, lambda etapa: _set_etapa(job_id, etapa))

    try:
        _append_job_log(job_id, 'Execução iniciada', 'info', 'Início')
        _update_job(job_id, 'em_andamento', etapa_atual='Consultando documento no PagBank...')
        log.info(f'[{job_id}] Consulta FV: {req.get("documento")}')

        from main import consultar_documento_fv

        resultado = consultar_documento_fv(
            documento=req['documento'],
            fv_usuario=req['fv_usuario'],
            fv_senha=req['fv_senha'],
            headless=req.get('headless', True),
            screenshot_dir=screenshot_dir,
            job_id=job_id,
        )

        if not resultado.get('sucesso'):
            _update_job(
                job_id, 'erro',
                resultado={'tipo_job': 'consulta_documento', 'detalhe': resultado},
                erro=resultado.get('erro', 'Erro na consulta do documento'),
                etapa_atual='Erro na consulta',
            )
            return

        _update_job(
            job_id, 'concluido',
            resultado={'tipo_job': 'consulta_documento', 'detalhe': resultado},
            etapa_atual='Consulta concluída',
        )
        log.info(f'[{job_id}] Consulta concluída: {resultado.get("situacao")}')

    except Exception as e:
        log.exception(f'[{job_id}] Erro inesperado na consulta')
        _update_job(job_id, 'erro', erro=str(e), etapa_atual='Erro inesperado')
    finally:
        remover(job_id)


def _executar_job(job_id: str, req: dict) -> None:
    from progresso import registrar, remover

    screenshot_dir = os.path.join(SCREENSHOT_DIR, job_id)
    os.makedirs(screenshot_dir, exist_ok=True)

    registrar(job_id, lambda etapa: _set_etapa(job_id, etapa))

    try:
        _append_job_log(job_id, 'Execução iniciada', 'info', 'Início')
        _update_job(job_id, 'em_andamento', etapa_atual='Iniciando automação...')

        from main import AUTOMACAO_CODIGO_VERSAO
        log.info(f'[{job_id}] Versão automação: {AUTOMACAO_CODIGO_VERSAO}')

        # --- Etapa 1: Cadastro na Força de Vendas ---
        log.info(f'[{job_id}] Iniciando cadastro FV para estab {req["estabelecimento_id"]}')
        log.info(f'[{job_id}] webmail_url={req.get("webmail_url")}')
        from main import cadastrar_fv

        fv_resultado = cadastrar_fv(
            dados=req['dados'],
            fv_usuario=req['fv_usuario'],
            fv_senha=req['fv_senha'],
            headless=req.get('headless', True),
            screenshot_dir=screenshot_dir,
            job_id=job_id,
        )

        if not fv_resultado.get('sucesso'):
            erro_amigavel = _formatar_erro_fv(fv_resultado)
            _update_job(
                job_id, 'erro',
                resultado={'etapa': 'cadastro_fv', 'detalhe': fv_resultado},
                erro=erro_amigavel,
                etapa_atual=erro_amigavel,
            )
            return

        log.info(f'[{job_id}] Cadastro FV concluído — aguardando email...')
        _set_etapa(job_id, 'Cadastro PagBank concluído — iniciando e-mail...')

        # --- Etapa 2: Validação de email e criação de senha ---
        from validacao_email import validar_email

        email_resultado = validar_email(
            webmail_url=req['webmail_url'],
            webmail_usuario=req['webmail_usuario'],
            webmail_senha=req['webmail_senha'],
            senha_6=req['senha_6'],
            headless=req.get('headless', True),
            screenshot_dir=screenshot_dir,
            aguardar_email_seg=req.get('aguardar_email_seg', 90),
            job_id=job_id,
        )

        resultado_final = {
            'etapa_fv': fv_resultado,
            'etapa_email': email_resultado,
            'senha_6': req['senha_6'],
        }

        if email_resultado.get('sucesso'):
            _set_etapa(job_id, 'Buscando Safepay ID no portal FV...')
            from main import buscar_safepay_id_fv

            email_suffix = '@express.app.br'
            safepay_resultado = buscar_safepay_id_fv(
                documento=req['dados']['cpf_cnpj'],
                fv_usuario=req['fv_usuario'],
                fv_senha=req['fv_senha'],
                email_suffix=email_suffix,
                headless=req.get('headless', True),
                screenshot_dir=screenshot_dir,
                job_id=job_id,
            )
            resultado_final['etapa_safepay'] = safepay_resultado
            if safepay_resultado.get('sucesso'):
                resultado_final['safepay_id'] = safepay_resultado.get('safepay_id')
                log.info(f'[{job_id}] Safepay ID encontrado: {safepay_resultado.get("safepay_id")}')
            else:
                log.warning(f'[{job_id}] Safepay ID não encontrado: {safepay_resultado.get("erro")}')

            _update_job(job_id, 'concluido', resultado=resultado_final, etapa_atual='Concluído com sucesso')
            log.info(f'[{job_id}] Cadastro concluído (proposta comercial fica para ação manual)')
        else:
            _update_job(
                job_id, 'erro_email',
                resultado=resultado_final,
                erro=email_resultado.get('erro', 'Erro na validação de email'),
                etapa_atual='Erro na etapa de e-mail',
            )

    except Exception as e:
        log.exception(f'[{job_id}] Erro inesperado')
        _update_job(job_id, 'erro', erro=str(e), etapa_atual='Erro inesperado')
    finally:
        remover(job_id)


def _executar_aceitar_proposta(job_id: str, req: dict) -> None:
    from progresso import registrar, remover

    screenshot_dir = os.path.join(SCREENSHOT_DIR, job_id)
    os.makedirs(screenshot_dir, exist_ok=True)

    registrar(job_id, lambda etapa: _set_etapa(job_id, etapa))

    try:
        _append_job_log(job_id, 'Execução iniciada', 'info', 'Início')
        _update_job(job_id, 'em_andamento', etapa_atual='Aceitando proposta comercial...')
        log.info(f'[{job_id}] Aceitar proposta standalone: estab {req.get("estabelecimento_id")}')

        resultado = _executar_etapa_proposta(job_id, req, screenshot_dir)

        if resultado.get('sucesso'):
            _update_job(
                job_id, 'concluido',
                resultado={'tipo_job': 'aceitar_proposta', 'detalhe': resultado},
                etapa_atual='Proposta aceita com sucesso',
            )
            log.info(f'[{job_id}] Proposta aceita com sucesso')
        else:
            _update_job(
                job_id, 'erro_proposta',
                resultado={'tipo_job': 'aceitar_proposta', 'detalhe': resultado},
                erro=resultado.get('erro', 'Erro ao aceitar proposta'),
                etapa_atual='Erro ao aceitar proposta',
            )

    except Exception as e:
        log.exception(f'[{job_id}] Erro inesperado ao aceitar proposta')
        _update_job(job_id, 'erro_proposta', erro=str(e), etapa_atual='Erro inesperado')
    finally:
        remover(job_id)


# ----------------------------------------------------------------
# Endpoints
# ----------------------------------------------------------------
@app.get('/health', tags=['Sistema'])
async def health():
    """Verificação de disponibilidade da API."""
    from main import AUTOMACAO_CODIGO_VERSAO

    return {
        'ok': True,
        'timestamp': datetime.now().isoformat(),
        'codigo_versao': AUTOMACAO_CODIGO_VERSAO,
    }


@app.post('/cadastrar', tags=['Automação'], status_code=202)
async def iniciar_cadastro(request: CadastrarRequest, x_api_key: str = Header(...)):
    """
    Inicia um job de cadastro assíncrono na Força de Vendas PagBank.
    Retorna o `job_id` para acompanhar via GET /status/{job_id}.
    """
    _autenticar(x_api_key)

    # Evita job duplicado para o mesmo estabelecimento em andamento
    with _db_lock:
        with _get_conn() as conn:
            em_andamento = conn.execute(
                "SELECT id FROM jobs WHERE estabelecimento_id=? AND status IN ('pendente','em_andamento')",
                (request.estabelecimento_id,),
            ).fetchone()

    if em_andamento:
        return JSONResponse(
            status_code=409,
            content={
                'detail': 'Já existe um job em andamento para este estabelecimento.',
                'job_id': em_andamento['id'],
            },
        )

    job_id = str(uuid.uuid4())
    agora  = datetime.now().isoformat()

    with _db_lock:
        with _get_conn() as conn:
            conn.execute(
                'INSERT INTO jobs (id, estabelecimento_id, status, etapa_atual, dados, criado_em, atualizado_em)'
                ' VALUES (?, ?, ?, ?, ?, ?, ?)',
                (job_id, request.estabelecimento_id,
                 'pendente', 'Aguardando início',
                 json.dumps(request.model_dump(), ensure_ascii=False),
                 agora, agora),
            )
            conn.commit()

    thread = threading.Thread(
        target=_executar_job,
        args=(job_id, request.model_dump()),
        daemon=True,
    )
    thread.start()

    log.info(f'Job {job_id} criado para estab {request.estabelecimento_id}')
    return {'job_id': job_id, 'status': 'pendente'}


@app.post('/consultar-documento', tags=['Automação'], status_code=202)
async def consultar_documento(request: ConsultarDocumentoRequest, x_api_key: str = Header(...)):
    """
    Consulta se um CPF/CNPJ pode ser cadastrado na Força de Vendas PagBank.
    Retorna `job_id` para acompanhar via GET /status/{job_id}.
    """
    _autenticar(x_api_key)

    job_id = str(uuid.uuid4())
    agora = datetime.now().isoformat()

    with _db_lock:
        with _get_conn() as conn:
            conn.execute(
                'INSERT INTO jobs (id, estabelecimento_id, status, etapa_atual, dados, criado_em, atualizado_em)'
                ' VALUES (?, ?, ?, ?, ?, ?, ?)',
                (
                    job_id, 0, 'pendente', 'Aguardando consulta',
                    json.dumps(request.model_dump(), ensure_ascii=False),
                    agora, agora,
                ),
            )
            conn.commit()

    thread = threading.Thread(
        target=_executar_consulta_documento,
        args=(job_id, request.model_dump()),
        daemon=True,
    )
    thread.start()

    log.info(f'Job consulta {job_id} criado para documento {request.documento}')
    return {'job_id': job_id, 'status': 'pendente'}


@app.post('/buscar-safepay-id', tags=['Automação'], status_code=202)
async def buscar_safepay_id(request: BuscarSafepayRequest, x_api_key: str = Header(...)):
    """
    Pesquisa cliente no FV por CPF/CNPJ e retorna Safepay ID do e-mail @express.app.br.
    """
    _autenticar(x_api_key)

    job_id = str(uuid.uuid4())
    agora = datetime.now().isoformat()

    with _db_lock:
        with _get_conn() as conn:
            conn.execute(
                'INSERT INTO jobs (id, estabelecimento_id, status, etapa_atual, dados, criado_em, atualizado_em)'
                ' VALUES (?, ?, ?, ?, ?, ?, ?)',
                (
                    job_id, request.estabelecimento_id, 'pendente', 'Aguardando busca Safepay',
                    json.dumps(request.model_dump(), ensure_ascii=False),
                    agora, agora,
                ),
            )
            conn.commit()

    thread = threading.Thread(
        target=_executar_busca_safepay,
        args=(job_id, request.model_dump()),
        daemon=True,
    )
    thread.start()

    log.info(f'Job Safepay {job_id} criado para estab {request.estabelecimento_id}')
    return {'job_id': job_id, 'status': 'pendente'}


@app.post('/retentar-email', tags=['Automação'], status_code=202)
async def retentar_email(request: RetentarEmailRequest, x_api_key: str = Header(...)):
    """
    Retenta apenas a etapa de e-mail (login Roundcube + criar senha PagBank).
    Usado quando o cadastro FV foi concluido mas o e-mail falhou (status erro_email).
    """
    _autenticar(x_api_key)

    job_id = str(uuid.uuid4())
    agora  = datetime.now().isoformat()

    with _db_lock:
        with _get_conn() as conn:
            conn.execute(
                'INSERT INTO jobs (id, estabelecimento_id, status, etapa_atual, dados, criado_em, atualizado_em)'
                ' VALUES (?, ?, ?, ?, ?, ?, ?)',
                (job_id, request.estabelecimento_id,
                 'pendente', 'Aguardando início',
                 json.dumps(request.model_dump(), ensure_ascii=False),
                 agora, agora),
            )
            conn.commit()

    thread = threading.Thread(
        target=_executar_somente_email,
        args=(job_id, request.model_dump()),
        daemon=True,
    )
    thread.start()

    log.info(f'Job email-only {job_id} criado para estab {request.estabelecimento_id}')
    return {'job_id': job_id, 'status': 'pendente'}


@app.post('/aceitar-proposta', tags=['Automação'], status_code=202)
async def aceitar_proposta_endpoint(request: AceitarPropostaRequest, x_api_key: str = Header(...)):
    """
    Login no PagBank como cliente e aceite da proposta comercial pendente.
    Pode ser executado separadamente após a criação da senha.
    """
    _autenticar(x_api_key)

    job_id = str(uuid.uuid4())
    agora = datetime.now().isoformat()

    with _db_lock:
        with _get_conn() as conn:
            conn.execute(
                'INSERT INTO jobs (id, estabelecimento_id, status, etapa_atual, dados, criado_em, atualizado_em)'
                ' VALUES (?, ?, ?, ?, ?, ?, ?)',
                (
                    job_id, request.estabelecimento_id, 'pendente', 'Aguardando aceite de proposta',
                    json.dumps(request.model_dump(), ensure_ascii=False),
                    agora, agora,
                ),
            )
            conn.commit()

    thread = threading.Thread(
        target=_executar_aceitar_proposta,
        args=(job_id, request.model_dump()),
        daemon=True,
    )
    thread.start()

    log.info(f'Job proposta {job_id} criado para estab {request.estabelecimento_id}')
    return {'job_id': job_id, 'status': 'pendente'}


@app.get('/status/{job_id}', tags=['Automação'])
async def consultar_status(job_id: str, x_api_key: str = Header(...)):
    """
    Retorna o status atual de um job.

    Valores de `status`:
    - `pendente`     — aguardando início
    - `em_andamento` — automation em execução
    - `concluido`    — cadastro + email + senha concluídos com sucesso
    - `erro`         — falha no cadastro FV
    - `erro_email`   — cadastro FV ok, mas falha no email/senha
    - `erro_proposta` — cadastro/senha ok, mas falha ao aceitar proposta
    """
    _autenticar(x_api_key)

    with _get_conn() as conn:
        row = conn.execute('SELECT * FROM jobs WHERE id=?', (job_id,)).fetchone()

    if not row:
        raise HTTPException(status_code=404, detail='Job não encontrado')

    return {
        'job_id':             row['id'],
        'estabelecimento_id': row['estabelecimento_id'],
        'status':             row['status'],
        'etapa_atual':       row['etapa_atual'],
        'resultado':          json.loads(row['resultado']) if row['resultado'] else None,
        'erro':               row['erro'],
        'criado_em':          row['criado_em'],
        'atualizado_em':      row['atualizado_em'],
        'logs':               _get_job_logs(job_id),
    }


@app.get('/jobs/{job_id}/screenshots', tags=['Automação'])
async def listar_screenshots(job_id: str, x_api_key: str = Header(...)):
    """Lista screenshots PNG salvos durante a execução do job."""
    _autenticar(x_api_key)

    dir_path = _screenshot_dir(job_id)
    if not os.path.isdir(dir_path):
        return {'job_id': job_id, 'screenshots': []}

    arquivos = sorted(
        [nome for nome in os.listdir(dir_path) if nome.endswith('.png')],
        key=lambda nome: os.path.getmtime(os.path.join(dir_path, nome)),
    )

    return {
        'job_id': job_id,
        'screenshots': [
            {
                'arquivo': nome,
                'tamanho': os.path.getsize(os.path.join(dir_path, nome)),
            }
            for nome in arquivos
        ],
    }


@app.get('/jobs/{job_id}/screenshots/{filename}', tags=['Automação'])
async def obter_screenshot(job_id: str, filename: str, x_api_key: str = Header(...)):
    """Retorna um screenshot PNG do job."""
    _autenticar(x_api_key)
    caminho = _resolver_arquivo_screenshot(job_id, filename)

    return FileResponse(caminho, media_type='image/png', filename=filename)


@app.get('/jobs', tags=['Administração'])
async def listar_jobs(
    status: str | None = None,
    limite: int = 50,
    x_api_key: str = Header(...),
):
    """Lista jobs recentes (uso administrativo)."""
    _autenticar(x_api_key)

    query = 'SELECT id, estabelecimento_id, status, erro, criado_em, atualizado_em FROM jobs'
    params: list = []

    if status:
        query += ' WHERE status=?'
        params.append(status)

    query += ' ORDER BY criado_em DESC LIMIT ?'
    params.append(limite)

    with _get_conn() as conn:
        rows = conn.execute(query, params).fetchall()

    return [dict(r) for r in rows]
