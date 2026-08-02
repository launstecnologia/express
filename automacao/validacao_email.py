# validacao_email.py
# Acessa o Roundcube Webmail, localiza o email do PagBank
# "Crie sua senha no PagSeguro" e clica em "Finalizar cadastro"
# Aceita dados dinâmicos via parametro (uso pela API) ou constantes CLI

from __future__ import annotations

import json
import sys
import time
import logging
import argparse
import os
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

ASSUNTO_ALVO = 'Crie sua senha no PagSeguro'


class ValidadorEmail:
    """Encapsula a validação de email e criação de senha no PagBank."""

    def __init__(self, webmail_url: str, webmail_usuario: str, webmail_senha: str,
                 senha_6: str, headless: bool = True,
                 screenshot_dir: str = '/tmp/screenshots',
                 aguardar_email_seg: int = 60,
                 job_id: str | None = None):
        self.webmail_url = self._normalizar_webmail_url(webmail_url)
        self.webmail_usuario = webmail_usuario
        self.webmail_senha = webmail_senha
        self.senha_6 = senha_6
        self.headless = headless
        self.screenshot_dir = screenshot_dir
        self.aguardar_email_seg = aguardar_email_seg
        self.job_id = job_id
        self.driver = None
        self.wait = None
        self.screenshots: list[str] = []

        os.makedirs(screenshot_dir, exist_ok=True)

    def _etapa(self, mensagem: str) -> None:
        reportar_etapa(self.job_id, mensagem)

    # ----------------------------------------------------------------
    # Execucao principal
    # ----------------------------------------------------------------
    def executar(self) -> dict:
        """Executa o fluxo completo. Retorna dict com resultado."""
        try:
            self._etapa('Acessando webmail...')
            self.driver = self._iniciar_browser()
            self.wait = WebDriverWait(self.driver, 30)

            self._etapa('Fazendo login no webmail...')
            self._fazer_login_webmail()
            self._etapa('Aguardando e-mail do PagBank...')
            self._abrir_email_pagbank()
            self._etapa('Finalizando cadastro no PagBank...')
            self._clicar_finalizar_cadastro()
            self._etapa('Criando senha de acesso...')
            self._criar_senha_acesso()
            self._etapa('Confirmando senha...')
            self._confirmar_senha_acesso()

            self._etapa('E-mail e senha concluídos')
            log.info('VALIDACAO E SENHA CONCLUIDAS!')
            return {
                'sucesso': True,
                'email': self.webmail_usuario,
                'senha_6': self.senha_6,
                'screenshots': self.screenshots,
            }

        except Exception as e:
            log.error(f'ERRO: {str(e)}')
            if self.driver:
                self._salvar_screenshot('erro_fatal')
            return {
                'sucesso': False,
                'erro': str(e),
                'email': self.webmail_usuario,
                'screenshots': self.screenshots,
            }
        finally:
            if self.driver:
                self.driver.quit()
                log.info('Browser fechado')

    # ----------------------------------------------------------------
    # Browser
    # ----------------------------------------------------------------
    def _iniciar_browser(self):
        opcoes = Options()
        # Flags obrigatórias para Docker (sempre ativas)
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
        caminho = os.path.join(self.screenshot_dir, f'email_{nome}_{int(time.time())}.png')
        self.driver.save_screenshot(caminho)
        self.screenshots.append(caminho)
        log.info(f'Screenshot: {caminho}')
        return caminho

    # ----------------------------------------------------------------
    # Etapas
    # ----------------------------------------------------------------
    def _normalizar_webmail_url(self, url: str) -> str:
        url = (url or '').strip().rstrip('/')
        if not url:
            return url
        if '/roundcube' not in url.lower():
            url = f'{url}/roundcube'
        return url

    def _fazer_login_webmail(self):
        log.info('--- LOGIN WEBMAIL ---')
        log.info(f'URL configurada: {self.webmail_url}')

        self.driver.get(self.webmail_url)
        time.sleep(3)

        url_atual = self.driver.current_url
        titulo = self.driver.title or ''
        log.info(f'URL após carregamento: {url_atual}')
        log.info(f'Título da página: {titulo}')
        self._salvar_screenshot('pagina_inicial')

        if 'rcmloginuser' not in self.driver.page_source:
            raise Exception(
                f'Página de webmail incorreta. '
                f'Configurado: {self.webmail_url} | '
                f'Carregou: {url_atual} | '
                f'Título: {titulo}'
            )

        campo_user = self.wait.until(EC.presence_of_element_located((By.ID, 'rcmloginuser')))
        campo_user.clear()
        campo_user.send_keys(self.webmail_usuario)

        campo_senha = self.driver.find_element(By.ID, 'rcmloginpwd')
        campo_senha.clear()
        campo_senha.send_keys(self.webmail_senha)

        self.driver.find_element(By.ID, 'rcmloginsubmit').click()
        log.info('Clicou em Entrar')

        try:
            self.wait.until(EC.presence_of_element_located(
                (By.XPATH,
                 '//*[contains(@class,"mailbox") or contains(@id,"inbox") '
                 'or contains(@class,"message-list") or @id="messagelist"]')
            ))
        except TimeoutException:
            self.wait.until(EC.presence_of_element_located(
                (By.XPATH, '//table[contains(@class,"listing")] | //ul[@id="mailboxlist"]')
            ))

        log.info('Webmail carregado')
        time.sleep(2)

    def _abrir_email_pagbank(self):
        log.info(f'--- PROCURANDO EMAIL: "{ASSUNTO_ALVO}" ---')

        try:
            inbox = self.driver.find_element(
                By.XPATH,
                '//a[contains(@href,"INBOX") or contains(text(),"Caixa de entrada") '
                'or contains(text(),"Inbox")]',
            )
            inbox.click()
            time.sleep(2)
        except NoSuchElementException:
            pass

        try:
            btn = self.driver.find_element(
                By.XPATH,
                '//a[contains(@title,"Atualizar") or contains(@title,"Refresh") or contains(@title,"Check")]'
                ' | //button[contains(@title,"Atualizar")]',
            )
            btn.click()
            time.sleep(2)
        except NoSuchElementException:
            pass

        # Aguarda o email chegar se ainda nao estiver (com retry)
        tentativas = max(1, self.aguardar_email_seg // 15)
        email_encontrado = False

        for tentativa in range(tentativas):
            try:
                email_row = WebDriverWait(self.driver, 15).until(EC.element_to_be_clickable(
                    (By.XPATH,
                     f'//tr[contains(@class,"message")]//span[contains(text(),"{ASSUNTO_ALVO}")]'
                     f' | //li[contains(@class,"message")]//span[contains(text(),"{ASSUNTO_ALVO}")]'
                     f' | //*[contains(text(),"{ASSUNTO_ALVO}")]')
                ))
                email_row.click()
                email_encontrado = True
                log.info(f'Email encontrado na tentativa {tentativa + 1}')
                break
            except TimeoutException:
                log.info(f'Email nao encontrado — tentativa {tentativa + 1}/{tentativas}. Atualizando...')
                try:
                    btn = self.driver.find_element(
                        By.XPATH,
                        '//a[contains(@title,"Atualizar") or contains(@title,"Refresh")]'
                        ' | //button[contains(@title,"Atualizar")]',
                    )
                    btn.click()
                    time.sleep(5)
                except NoSuchElementException:
                    time.sleep(5)

        if not email_encontrado:
            self._salvar_screenshot('email_nao_encontrado')
            raise Exception(
                f'Email "{ASSUNTO_ALVO}" nao encontrado apos {self.aguardar_email_seg}s. '
                'Verifique se o cadastro foi finalizado.'
            )

        time.sleep(3)
        self._salvar_screenshot('email_aberto')

    def _clicar_finalizar_cadastro(self):
        log.info('--- PROCURANDO BOTAO "Finalizar cadastro" ---')

        clicou = self._tentar_clicar_finalizar()

        if not clicou:
            iframes = self.driver.find_elements(By.TAG_NAME, 'iframe')
            log.info(f'Tentando em {len(iframes)} iframes...')
            for i, iframe in enumerate(iframes):
                try:
                    self.driver.switch_to.frame(iframe)
                    clicou = self._tentar_clicar_finalizar(timeout=5)
                    if clicou:
                        break
                    self.driver.switch_to.default_content()
                except Exception as e:
                    log.warning(f'Erro no iframe {i}: {e}')
                    self.driver.switch_to.default_content()

        if not clicou:
            self._salvar_screenshot('botao_nao_encontrado')
            raise Exception('"Finalizar cadastro" nao encontrado no email.')

        log.info('Clicou em Finalizar cadastro!')
        time.sleep(5)

        abas = self.driver.window_handles
        if len(abas) > 1:
            self.driver.switch_to.window(abas[-1])
            log.info(f'Nova aba: {self.driver.current_url}')
            time.sleep(3)

        self._salvar_screenshot('pagina_criar_senha')

    def _tentar_clicar_finalizar(self, timeout: int = 10) -> bool:
        seletores = [
            '//a[contains(text(),"Finalizar cadastro")]',
            '//button[contains(text(),"Finalizar cadastro")]',
            '//*[contains(text(),"Finalizar cadastro")]',
            '//a[contains(@href,"pagbank") and contains(@href,"cadastro")]',
            '//a[contains(@href,"pagbank") and contains(@href,"senha")]',
        ]
        for seletor in seletores:
            try:
                el = WebDriverWait(self.driver, timeout).until(
                    EC.element_to_be_clickable((By.XPATH, seletor))
                )
                self.driver.execute_script('arguments[0].scrollIntoView(true);', el)
                time.sleep(0.3)
                self.driver.execute_script('arguments[0].click();', el)
                return True
            except (TimeoutException, NoSuchElementException):
                continue
        return False

    def _criar_senha_acesso(self):
        log.info('--- CRIANDO SENHA DE ACESSO ---')

        if len(self.senha_6) != 6 or not self.senha_6.isdigit():
            raise ValueError(f'senha_6 deve ter exatamente 6 digitos numericos. Atual: "{self.senha_6}"')

        self.wait.until(EC.presence_of_element_located(
            (By.XPATH, '//*[contains(text(),"Crie a sua senha") or contains(text(),"6 números")]')
        ))
        time.sleep(1)

        campos = WebDriverWait(self.driver, 20).until(lambda d: d.find_elements(
            By.XPATH, '//*[@data-subcomponent-name="secret-box-input"]'
        ))

        if len(campos) < 6:
            self._salvar_screenshot('campos_senha_nao_encontrados')
            raise Exception(f'Esperava 6 campos de senha, encontrou {len(campos)}')

        for i, digito in enumerate(self.senha_6):
            campo = campos[i]
            self.driver.execute_script('arguments[0].scrollIntoView(true);', campo)
            campo.click()
            time.sleep(0.15)
            campo.send_keys(digito)
            time.sleep(0.15)

        time.sleep(1)

        erros = self.driver.find_elements(
            By.XPATH,
            '//*[contains(text(),"não atende") or contains(text(),"nao atende") '
            'or contains(text(),"inválida") or contains(text(),"invalida")]',
        )
        if erros and erros[0].text.strip():
            self._salvar_screenshot('senha_invalida')
            raise ValueError(f'Senha rejeitada: "{erros[0].text.strip()}"')

        try:
            WebDriverWait(self.driver, 15).until(EC.element_to_be_clickable(
                (By.XPATH, '//*[@data-cy="button-submit" and not(@disabled)]')
            ))
        except TimeoutException:
            pass

        btn = self.driver.find_element(By.XPATH, '//*[@data-cy="button-submit"]')
        self.driver.execute_script('arguments[0].click();', btn)
        log.info('Clicou em Continuar (criar senha)')
        time.sleep(3)
        self._salvar_screenshot('pos_criar_senha')

    def _confirmar_senha_acesso(self):
        log.info('--- CONFIRMANDO SENHA DE ACESSO ---')

        try:
            WebDriverWait(self.driver, 15).until(EC.presence_of_element_located(
                (By.XPATH, '//*[contains(text(),"Confirme a senha") or contains(text(),"confirme")]')
            ))
        except TimeoutException:
            log.warning('Tela de confirmacao nao detectada — pode ja ter avancado')
            return

        time.sleep(1)

        campos = WebDriverWait(self.driver, 20).until(lambda d: d.find_elements(
            By.XPATH, '//*[@data-subcomponent-name="secret-box-input"]'
        ))

        if len(campos) < 6:
            raise Exception(f'Esperava 6 campos de confirmacao, encontrou {len(campos)}')

        for i, digito in enumerate(self.senha_6):
            campo = campos[i]
            self.driver.execute_script('arguments[0].scrollIntoView(true);', campo)
            campo.click()
            time.sleep(0.15)
            campo.send_keys(digito)
            time.sleep(0.15)

        time.sleep(1)

        try:
            WebDriverWait(self.driver, 15).until(EC.element_to_be_clickable(
                (By.XPATH, '//*[@data-cy="button-submit" and not(@disabled)]')
            ))
        except TimeoutException:
            pass

        btn = self.driver.find_element(By.XPATH, '//*[@data-cy="button-submit"]')
        self.driver.execute_script('arguments[0].click();', btn)
        log.info('Clicou em Confirmar senha')
        time.sleep(3)
        self._salvar_screenshot('senha_confirmada')
        log.info('Senha confirmada com sucesso!')


# ----------------------------------------------------------------
# Funcao publica — usada pela API
# ----------------------------------------------------------------
def validar_email(webmail_url: str, webmail_usuario: str, webmail_senha: str,
                  senha_6: str, headless: bool = True,
                  screenshot_dir: str = '/tmp/screenshots',
                  aguardar_email_seg: int = 60,
                  job_id: str | None = None) -> dict:
    """
    Ponto de entrada publico para a API FastAPI.
    Retorna dict com chaves: sucesso, email, senha_6, screenshots, erro (se falhou).
    """
    validador = ValidadorEmail(
        webmail_url=webmail_url,
        webmail_usuario=webmail_usuario,
        webmail_senha=webmail_senha,
        senha_6=senha_6,
        headless=headless,
        screenshot_dir=screenshot_dir,
        aguardar_email_seg=aguardar_email_seg,
        job_id=job_id,
    )
    return validador.executar()


# ----------------------------------------------------------------
# CLI — uso local para testes
# ----------------------------------------------------------------
WEBMAIL_URL_CLI   = 'https://mail.expresspag.com.br'
EMAIL_USUARIO_CLI = 'cv@expresspag.com.br'
EMAIL_SENHA_CLI   = 'Conta@2026'
SENHA_6_CLI       = '539280'

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Validacao de email e senha PagBank')
    parser.add_argument('--webmail-url',     type=str, default=WEBMAIL_URL_CLI)
    parser.add_argument('--webmail-usuario', type=str, default=EMAIL_USUARIO_CLI)
    parser.add_argument('--webmail-senha',   type=str, default=EMAIL_SENHA_CLI)
    parser.add_argument('--senha-6',         type=str, default=SENHA_6_CLI)
    parser.add_argument('--headless',        action='store_true', default=False)
    parser.add_argument('--screenshot-dir',  type=str, default='screenshots')
    parser.add_argument('--aguardar',        type=int, default=60,
                        help='Segundos para aguardar o email chegar')
    args = parser.parse_args()

    resultado = validar_email(
        webmail_url=args.webmail_url,
        webmail_usuario=args.webmail_usuario,
        webmail_senha=args.webmail_senha,
        senha_6=args.senha_6,
        headless=args.headless,
        screenshot_dir=args.screenshot_dir,
        aguardar_email_seg=args.aguardar,
    )

    print(json.dumps(resultado, ensure_ascii=False, indent=2))
    sys.exit(0 if resultado['sucesso'] else 1)
