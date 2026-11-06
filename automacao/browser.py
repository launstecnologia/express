# browser.py
# Abre o Chrome no container com o driver da mesma versão e uma segunda
# tentativa quando a sessão morre ao nascer (perfil antigo ou extensão).

from __future__ import annotations

import logging
import os
import shutil
import subprocess
import tempfile
from pathlib import Path

from selenium import webdriver
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service

log = logging.getLogger(__name__)

_LOG_DRIVER = Path(tempfile.gettempdir()) / 'chromedriver-automacao.log'


def opcoes_chrome(
    *,
    headless: bool = True,
    extensao: str | None = None,
    user_data_dir: str | None = None,
    window: str = '1366,768',
    lang: str | None = None,
) -> Options:
    opcoes = Options()
    binario = shutil.which('google-chrome') or shutil.which('google-chrome-stable')
    if binario:
        opcoes.binary_location = binario

    for flag in (
        '--no-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--disable-software-rasterizer',
        '--no-first-run',
        '--no-default-browser-check',
        '--disable-crash-reporter',
        '--disable-breakpad',
        '--password-store=basic',
        '--remote-allow-origins=*',
        f'--window-size={window}',
        '--disable-blink-features=AutomationControlled',
    ):
        opcoes.add_argument(flag)

    if lang:
        opcoes.add_argument(f'--lang={lang}')

    if extensao:
        opcoes.add_argument(f'--disable-extensions-except={extensao}')
        opcoes.add_argument(f'--load-extension={extensao}')
    else:
        opcoes.add_argument('--disable-extensions')

    display = os.environ.get('DISPLAY')
    if headless and not (extensao and display):
        opcoes.add_argument('--headless=new')

    if user_data_dir:
        os.makedirs(user_data_dir, exist_ok=True)
        opcoes.add_argument(f'--user-data-dir={user_data_dir}')

    opcoes.add_experimental_option('excludeSwitches', ['enable-automation'])
    opcoes.add_experimental_option('useAutomationExtension', False)
    return opcoes


def criar_driver(opcoes: Options) -> webdriver.Chrome:
    if _LOG_DRIVER.exists():
        try:
            _LOG_DRIVER.unlink()
        except OSError:
            pass

    servico = Service(_caminho_driver(), log_output=str(_LOG_DRIVER))
    driver = webdriver.Chrome(service=servico, options=opcoes)
    driver.execute_script(
        "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
    )
    return driver


def criar_driver_resiliente(
    *,
    headless: bool = True,
    extensao: str | None = None,
    user_data_dir: str | None = None,
    window: str = '1366,768',
    lang: str | None = None,
) -> webdriver.Chrome:
    """Tenta abrir o Chrome. Se a sessão morrer, descarta o perfil e tenta sem extensão."""
    try:
        return criar_driver(opcoes_chrome(
            headless=headless,
            extensao=extensao,
            user_data_dir=user_data_dir,
            window=window,
            lang=lang,
        ))
    except Exception as primeira:
        log.warning('Chrome fechou na primeira tentativa: %s', primeira)
        if user_data_dir:
            esvaziar_perfil(user_data_dir)
        try:
            return criar_driver(opcoes_chrome(
                headless=headless,
                extensao=None,
                user_data_dir=None,
                window=window,
                lang=lang,
            ))
        except Exception as segunda:
            raise RuntimeError(_mensagem_falha(segunda)) from segunda


def esvaziar_perfil(perfil: str) -> None:
    if not perfil or not os.path.isdir(perfil):
        return
    for nome in os.listdir(perfil):
        if nome == '.profile.lock':
            continue
        caminho = os.path.join(perfil, nome)
        try:
            if os.path.isdir(caminho) and not os.path.islink(caminho):
                shutil.rmtree(caminho, ignore_errors=True)
            else:
                os.remove(caminho)
        except OSError as exc:
            log.warning('Não removeu %s do perfil Chrome: %s', nome, exc)


def _caminho_driver() -> str | None:
    for candidato in (
        os.getenv('CHROMEDRIVER'),
        '/usr/local/bin/chromedriver',
        '/usr/bin/chromedriver',
    ):
        if candidato and os.path.isfile(candidato) and os.access(candidato, os.X_OK):
            return candidato
    return None


def _versao_chrome() -> str:
    binario = shutil.which('google-chrome') or shutil.which('google-chrome-stable')
    if not binario:
        return 'não encontrado'
    try:
        saida = subprocess.check_output([binario, '--version'], text=True, timeout=8).strip()
        return saida or 'desconhecida'
    except Exception:
        return 'desconhecida'


def _trecho_log() -> str:
    try:
        texto = _LOG_DRIVER.read_text(encoding='utf-8', errors='replace')
    except OSError:
        return ''
    linhas = [linha.strip() for linha in texto.splitlines() if linha.strip()]
    if not linhas:
        return ''
    return ' '.join(linhas[-4:])[:500]


def _mensagem_falha(erro: Exception) -> str:
    partes = [
        'O Chrome da automação fechou ao abrir a sessão.',
        f'Chrome: {_versao_chrome()}.',
    ]
    trecho = _trecho_log()
    if trecho:
        partes.append(trecho)
    else:
        partes.append(str(erro).splitlines()[0][:300])
    partes.append('Reconstrua o serviço da automação se o erro repetir.')
    return ' '.join(partes)
