# aceitar_proposta.py
# Login no PagBank (portal do cliente) e aceite da proposta comercial pendente.

from __future__ import annotations

import json
import logging
import os
import re
import sys
import time
import argparse

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.common.exceptions import TimeoutException, NoSuchElementException
from webdriver_manager.chrome import ChromeDriverManager

from progresso import reportar as reportar_etapa

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%H:%M:%S',
)
log = logging.getLogger(__name__)

PAGBANK_LANDING_URL = 'https://pagbank.com.br/banco-completo-para-empreendedor'


class AceitadorProposta:
    """Login no PagBank como cliente e aceite de proposta comercial."""

    def __init__(
        self,
        documento: str,
        senha_6: str,
        email: str = '',
        email_suffix: str = 'express.app.br',
        headless: bool = True,
        screenshot_dir: str = '/tmp/screenshots',
        job_id: str | None = None,
    ):
        self.documento = re.sub(r'\D', '', documento or '')
        self.senha_6 = senha_6
        self.email = (email or '').strip()
        self.email_suffix = (email_suffix or 'express.app.br').lstrip('@').lower()
        self.headless = headless
        self.screenshot_dir = screenshot_dir
        self.job_id = job_id
        self.driver = None
        self.wait = None
        self.screenshots: list[str] = []

        os.makedirs(screenshot_dir, exist_ok=True)

    def _etapa(self, mensagem: str) -> None:
        reportar_etapa(self.job_id, mensagem)

    def executar(self) -> dict:
        if len(self.documento) not in (11, 14):
            return {'sucesso': False, 'erro': 'Documento inválido (CPF 11 ou CNPJ 14 dígitos).'}
        if len(self.senha_6) != 6 or not self.senha_6.isdigit():
            return {'sucesso': False, 'erro': 'senha_6 deve ter 6 dígitos numéricos.'}

        try:
            self._etapa('Acessando portal PagBank...')
            self.driver = self._iniciar_browser()
            self.wait = WebDriverWait(self.driver, 25)

            self._abrir_portal_e_entrar()
            self._etapa('Informando CPF/CNPJ...')
            self._informar_documento()
            self._etapa('Autenticando conta...')
            self._resolver_login_pos_documento()
            self._etapa('Aceitando proposta comercial...')
            self._aceitar_proposta()

            self._etapa('Proposta aceita com sucesso')
            log.info('PROPOSTA ACEITA COM SUCESSO!')
            return {
                'sucesso': True,
                'documento': self.documento,
                'email': self.email,
                'screenshots': self.screenshots,
            }

        except Exception as e:
            log.error(f'ERRO: {e}')
            if self.driver:
                self._salvar_screenshot('erro_fatal')
            return {
                'sucesso': False,
                'erro': str(e),
                'documento': self.documento,
                'email': self.email,
                'screenshots': self.screenshots,
            }
        finally:
            if self.driver:
                self.driver.quit()
                log.info('Browser fechado')

    def _iniciar_browser(self):
        opcoes = Options()
        opcoes.add_argument('--no-sandbox')
        opcoes.add_argument('--disable-dev-shm-usage')
        opcoes.add_argument('--disable-gpu')
        opcoes.add_argument('--no-zygote')
        opcoes.add_argument('--disable-software-rasterizer')
        opcoes.add_argument('--disable-extensions')

        if self.headless:
            opcoes.add_argument('--headless')

        opcoes.add_argument('--window-size=1366,768')
        opcoes.add_argument('--disable-blink-features=AutomationControlled')
        opcoes.add_experimental_option('excludeSwitches', ['enable-automation'])
        opcoes.add_experimental_option('useAutomationExtension', False)

        service = Service(ChromeDriverManager().install())
        driver = webdriver.Chrome(service=service, options=opcoes)
        driver.execute_script(
            "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
        )
        log.info('Browser iniciado')
        return driver

    def _salvar_screenshot(self, nome: str) -> str:
        caminho = os.path.join(self.screenshot_dir, f'proposta_{nome}_{int(time.time())}.png')
        self.driver.save_screenshot(caminho)
        self.screenshots.append(caminho)
        log.info(f'Screenshot: {caminho}')
        return caminho

    def _clicar_js(self, elemento) -> None:
        self.driver.execute_script('arguments[0].scrollIntoView({block: "center"});', elemento)
        time.sleep(0.2)
        self.driver.execute_script('arguments[0].click();', elemento)

    def _fechar_cookies(self) -> None:
        seletores = [
            '//button[contains(text(),"OK")]',
            '//button[contains(text(),"Aceitar")]',
            '//*[contains(@class,"cookie")]//button',
        ]
        for seletor in seletores:
            try:
                btn = WebDriverWait(self.driver, 3).until(
                    EC.element_to_be_clickable((By.XPATH, seletor))
                )
                self._clicar_js(btn)
                time.sleep(0.5)
                return
            except TimeoutException:
                continue

    def _abrir_portal_e_entrar(self) -> None:
        log.info('--- ABRINDO PORTAL PAGBANK ---')
        self.driver.get(PAGBANK_LANDING_URL)
        time.sleep(2)
        self._fechar_cookies()

        seletores_entrar = [
            '//*[@id="__next"]/header/div/div[4]/div/div/a[1]',
            '//header//a[contains(text(),"Entrar")]',
            '//a[contains(@href,"acesso.pagbank")]',
        ]
        for seletor in seletores_entrar:
            try:
                btn = self.wait.until(EC.element_to_be_clickable((By.XPATH, seletor)))
                self._clicar_js(btn)
                break
            except TimeoutException:
                continue
        else:
            self._salvar_screenshot('botao_entrar_nao_encontrado')
            raise Exception('Botão "Entrar" não encontrado na página do PagBank.')

        time.sleep(2)
        if len(self.driver.window_handles) > 1:
            self.driver.switch_to.window(self.driver.window_handles[-1])

        self._salvar_screenshot('tela_login')

    def _informar_documento(self) -> None:
        log.info('--- INFORMANDO DOCUMENTO ---')
        campo = self.wait.until(EC.presence_of_element_located((By.ID, 'user')))
        campo.clear()
        campo.send_keys(self.documento)
        time.sleep(0.3)

        btn = self.wait.until(EC.element_to_be_clickable((By.ID, 'continue')))
        self._clicar_js(btn)
        time.sleep(2)
        self._salvar_screenshot('pos_documento')

    def _resolver_login_pos_documento(self) -> None:
        log.info('--- RESOLVENDO LOGIN PÓS-DOCUMENTO ---')
        for _ in range(3):
            if self._tela_proposta_pendente():
                log.info('Proposta pendente detectada — login concluído')
                return

            if self._tela_selecao_email():
                self._etapa('Selecionando e-mail @express.app.br...')
                self._preencher_email_express()
                continue

            if self._tela_senha():
                self._preencher_senha_e_entrar()
                time.sleep(3)
                continue

            time.sleep(2)

        if not self._tela_proposta_pendente() and not self._tela_aceitar_proposta():
            self._salvar_screenshot('login_nao_concluido')
            raise Exception('Não foi possível concluir o login no PagBank.')

    def _tela_selecao_email(self) -> bool:
        try:
            html = (self.driver.page_source or '').lower()
            if 'informe o e-mail' in html or 'qual conta você quer acessar' in html:
                return bool(self.driver.find_elements(By.ID, 'user'))
        except Exception:
            pass
        return False

    def _tela_senha(self) -> bool:
        html = (self.driver.page_source or '').lower()
        if 'digite sua senha' in html or 'secret-box-input' in html:
            return True
        return bool(self.driver.find_elements(
            By.XPATH, '//*[@data-subcomponent-name="secret-box-input"]'
        ))

    def _tela_proposta_pendente(self) -> bool:
        html = (self.driver.page_source or '').lower()
        return 'propostas pendentes' in html or 'pendente de aceite' in html

    def _tela_aceitar_proposta(self) -> bool:
        html = (self.driver.page_source or '').lower()
        return 'aceitar proposta' in html or 'aceitar essa proposta' in html

    def _email_para_usar(self) -> str:
        if self.email and self.email_suffix in self.email.lower():
            return self.email

        try:
            for el in self.driver.find_elements(By.XPATH, '//*[contains(text(),"@")]'):
                texto = (el.text or '').strip()
                if self.email_suffix in texto.lower() and '@' in texto:
                    candidato = re.search(r'[\w.\-+]+@[\w.\-]+\.\w+', texto)
                    if candidato:
                        return candidato.group(0)
        except Exception:
            pass

        if self.email:
            return self.email

        raise Exception(
            f'E-mail @{self.email_suffix} não encontrado na tela de seleção.'
        )

    def _preencher_email_express(self) -> None:
        email = self._email_para_usar()
        log.info(f'Usando e-mail: {email}')

        campo = self.wait.until(EC.presence_of_element_located((By.ID, 'user')))
        campo.clear()
        campo.send_keys(email)
        time.sleep(0.3)

        btn = self.wait.until(EC.element_to_be_clickable((By.ID, 'continue')))
        self._clicar_js(btn)
        time.sleep(2)
        self._salvar_screenshot('pos_email')

    def _preencher_senha_e_entrar(self) -> None:
        log.info('--- PREENChendo SENHA ---')

        campos = WebDriverWait(self.driver, 20).until(
            lambda d: d.find_elements(
                By.XPATH, '//*[@data-subcomponent-name="secret-box-input"]'
            )
        )

        if len(campos) < 6:
            self._salvar_screenshot('campos_senha_nao_encontrados')
            raise Exception(f'Esperava 6 campos de senha, encontrou {len(campos)}')

        for i, digito in enumerate(self.senha_6):
            campo = campos[i]
            self._clicar_js(campo)
            campo.send_keys(digito)
            time.sleep(0.12)

        time.sleep(0.5)

        seletores_entrar = [
            (By.ID, 'enter'),
            (By.XPATH, '//button[contains(text(),"Entrar")]'),
            (By.XPATH, '//*[@data-cy="button-submit"]'),
        ]
        for by, seletor in seletores_entrar:
            try:
                btn = WebDriverWait(self.driver, 8).until(
                    EC.element_to_be_clickable((by, seletor))
                )
                self._clicar_js(btn)
                log.info('Clicou em Entrar')
                self._salvar_screenshot('pos_senha')
                return
            except TimeoutException:
                continue

        raise Exception('Botão "Entrar" não encontrado após preencher a senha.')

    def _aceitar_proposta(self) -> None:
        log.info('--- ACEITANDO PROPOSTA ---')

        if self._tela_proposta_pendente():
            seletores_continuar = [
                '//*[@id="__next"]/div/div/main/div/div/div/div[2]/div/button',
                '//button[contains(text(),"Continuar")]',
            ]
            clicou = False
            for seletor in seletores_continuar:
                try:
                    btn = WebDriverWait(self.driver, 15).until(
                        EC.element_to_be_clickable((By.XPATH, seletor))
                    )
                    self._clicar_js(btn)
                    clicou = True
                    break
                except TimeoutException:
                    continue

            if not clicou:
                self._salvar_screenshot('continuar_proposta_nao_encontrado')
                raise Exception('Botão "Continuar" da proposta pendente não encontrado.')

            time.sleep(3)
            self._salvar_screenshot('detalhe_proposta')

        if not self._tela_aceitar_proposta():
            html = (self.driver.page_source or '').lower()
            if 'proposta' not in html:
                log.warning('Tela de proposta não detectada — pode já estar aceita.')
                return

        self.driver.execute_script('window.scrollTo(0, document.body.scrollHeight);')
        time.sleep(1)

        seletores_aceitar = [
            '//*[@id="__next"]/div/div/main/div/div/div[2]/div/div[7]/button[2]',
            '//button[contains(text(),"Aceitar proposta")]',
        ]
        for seletor in seletores_aceitar:
            try:
                btn = WebDriverWait(self.driver, 15).until(
                    EC.element_to_be_clickable((By.XPATH, seletor))
                )
                self._clicar_js(btn)
                time.sleep(3)
                self._salvar_screenshot('proposta_aceita')
                log.info('Proposta aceita!')
                return
            except TimeoutException:
                continue

        self._salvar_screenshot('aceitar_proposta_nao_encontrado')
        raise Exception('Botão "Aceitar proposta" não encontrado.')


def aceitar_proposta(
    documento: str,
    senha_6: str,
    email: str = '',
    email_suffix: str = 'express.app.br',
    headless: bool = True,
    screenshot_dir: str = '/tmp/screenshots',
    job_id: str | None = None,
) -> dict:
    aceitador = AceitadorProposta(
        documento=documento,
        senha_6=senha_6,
        email=email,
        email_suffix=email_suffix,
        headless=headless,
        screenshot_dir=screenshot_dir,
        job_id=job_id,
    )
    return aceitador.executar()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Aceitar proposta PagBank')
    parser.add_argument('--documento', required=True)
    parser.add_argument('--senha-6', required=True)
    parser.add_argument('--email', default='')
    parser.add_argument('--headless', action='store_true', default=False)
    parser.add_argument('--screenshot-dir', default='screenshots')
    args = parser.parse_args()

    resultado = aceitar_proposta(
        documento=args.documento,
        senha_6=args.senha_6,
        email=args.email,
        headless=args.headless,
        screenshot_dir=args.screenshot_dir,
    )
    print(json.dumps(resultado, ensure_ascii=False, indent=2))
    sys.exit(0 if resultado['sucesso'] else 1)
