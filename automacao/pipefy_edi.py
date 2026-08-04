# pipefy_edi.py
# Abre chamado no Pipefy PagBank — Novas Ativações EDI (replicação de token 1xN)

from __future__ import annotations

import logging
import os
import re
import time
import argparse
import json

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
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

DEFAULT_PAGE_URL = (
    'https://app.pipefy.com/organizations/142456/interfaces/'
    '3668b8ed-d930-4bcf-8038-8a00d3ed6901/pages/243d9728-5742-47d1-b791-ce04f061ac13'
)


class PipefyEdiSolicitador:
    """Preenche o formulário Novas Ativações — Replicação do token API EDI."""

    def __init__(
        self,
        page_url: str,
        email: str,
        token: str,
        id_origem: str,
        descricao: str,
        tipo_solicitacao: str = 'Replicação do token API EDI',
        razao_social: str = '',
        cnpj: str = '',
        telefone: str = '',
        headless: bool = True,
        screenshot_dir: str = '/tmp/screenshots',
        job_id: str | None = None,
    ):
        self.page_url = page_url or DEFAULT_PAGE_URL
        self.email = (email or '').strip()
        self.token = (token or '').strip()
        self.id_origem = (id_origem or '').strip()
        self.descricao = descricao or ''
        self.tipo_solicitacao = tipo_solicitacao or 'Replicação do token API EDI'
        self.razao_social = (razao_social or '').strip()
        self.cnpj = re.sub(r'\D', '', cnpj or '')
        self.telefone = (telefone or '').strip()
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
        if not self.token or not self.id_origem:
            return {'sucesso': False, 'erro': 'Token e ID Origem são obrigatórios.'}
        if not self.descricao.strip():
            return {'sucesso': False, 'erro': 'Descrição com IDs é obrigatória.'}

        try:
            self._etapa('Abrindo portal Pipefy EDI...')
            self.driver = self._iniciar_browser()
            self.wait = WebDriverWait(self.driver, 30)

            self.driver.get(self.page_url)
            time.sleep(3)
            self._salvar_screenshot('portal_inicial')

            self._etapa('Selecionando Novas Ativações - EDI...')
            self._clicar_novas_ativacoes()
            time.sleep(2)
            self._salvar_screenshot('formulario')

            self._etapa('Preenchendo formulário de replicação...')
            self._preencher_formulario()
            self._salvar_screenshot('formulario_preenchido')

            self._etapa('Enviando chamado...')
            card_id = self._enviar()
            self._salvar_screenshot('enviado')

            self._etapa('Chamado Pipefy EDI enviado')
            return {
                'sucesso': True,
                'card_id': card_id,
                'email': self.email,
                'id_origem': self.id_origem,
                'screenshots': self.screenshots,
            }
        except Exception as e:
            log.error(f'ERRO Pipefy EDI: {e}')
            if self.driver:
                self._salvar_screenshot('erro_fatal')
            return {
                'sucesso': False,
                'erro': str(e).split('Stacktrace:')[0].strip(),
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
            opcoes.add_argument('--headless=new')
        opcoes.add_argument('--window-size=1366,900')
        opcoes.add_argument('--disable-blink-features=AutomationControlled')
        opcoes.add_experimental_option('excludeSwitches', ['enable-automation'])
        opcoes.add_experimental_option('useAutomationExtension', False)

        service = Service(ChromeDriverManager().install())
        driver = webdriver.Chrome(service=service, options=opcoes)
        driver.execute_script(
            "Object.defineProperty(navigator, 'webdriver', {get: () => undefined})"
        )
        return driver

    def _salvar_screenshot(self, nome: str) -> str:
        caminho = os.path.join(self.screenshot_dir, f'pipefy_{nome}_{int(time.time())}.png')
        self.driver.save_screenshot(caminho)
        self.screenshots.append(caminho)
        log.info(f'Screenshot: {caminho}')
        return caminho

    def _clicar_js(self, elemento) -> None:
        self.driver.execute_script('arguments[0].scrollIntoView({block: "center"});', elemento)
        time.sleep(0.2)
        self.driver.execute_script('arguments[0].click();', elemento)

    def _clicar_novas_ativacoes(self) -> None:
        seletores = [
            '//*[contains(normalize-space(.),"Novas Ativações - EDI") or contains(normalize-space(.),"Novas Ativações")]',
            '//button[contains(.,"Novas Ativações")]',
            '//a[contains(.,"Novas Ativações")]',
            '//div[contains(.,"Habilitação de fluxo EDI")]',
        ]
        for seletor in seletores:
            try:
                elementos = self.driver.find_elements(By.XPATH, seletor)
                for el in elementos:
                    texto = (el.text or '').strip()
                    if 'Novas Ativações' in texto or 'Habilitação de fluxo EDI' in texto:
                        # Preferir o card/botão clicável mais específico
                        clicavel = el
                        try:
                            clicavel = el.find_element(
                                By.XPATH,
                                './ancestor-or-self::*[self::button or self::a or contains(@class,"card") or @role="button"][1]',
                            )
                        except NoSuchElementException:
                            pass
                        self._clicar_js(clicavel)
                        log.info('Clicou em Novas Ativações - EDI')
                        return
            except Exception:
                continue

        # Se o formulário já estiver aberto (URL de page específica), segue
        if self._campo_visivel_por_label('Token') or self._campo_visivel_por_label('tipo da sua solicitação'):
            log.info('Formulário já visível — pulando clique em Novas Ativações')
            return

        self._salvar_screenshot('novas_ativacoes_nao_encontrado')
        raise Exception('Opção "Novas Ativações - EDI" não encontrada no portal Pipefy.')

    def _campo_visivel_por_label(self, trecho: str) -> bool:
        xpath = (
            f'//*[contains(translate(normalize-space(.),'
            f'"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),'
            f'"{trecho.lower()}")]'
        )
        try:
            return len(self.driver.find_elements(By.XPATH, xpath)) > 0
        except Exception:
            return False

    def _preencher_formulario(self) -> None:
        # Campos opcionais / variáveis conforme o layout do Pipefy
        self._preencher_por_label(['mail de mesmo domínio', 'e-mail', 'email'], self.email, opcional=True)
        self._selecionar_tipo_solicitacao()
        self._preencher_por_label(['* Token', 'Token'], self.token, opcional=False)
        self._preencher_por_label(['ID Origem', 'ID origem'], self.id_origem, opcional=False)
        self._preencher_por_label(['Descrição', 'Descricao'], self.descricao, opcional=True, multilinha=True)

        # Campos que podem aparecer em outras etapas do formulário
        if self.razao_social:
            self._preencher_por_label(['Razão social', 'Razao social', 'Nome da empresa'], self.razao_social, opcional=True)
        if self.cnpj:
            self._preencher_por_label(['CNPJ', 'CPF/CNPJ', 'Documento'], self.cnpj, opcional=True)
        if self.telefone:
            self._preencher_por_label(['Telefone', 'Celular'], self.telefone, opcional=True)

    def _selecionar_tipo_solicitacao(self) -> None:
        # Abre o select/dropdown
        triggers = [
            '//*[contains(normalize-space(.),"Qual o tipo da sua solicitação")]/following::*[self::button or self::div[@role="button"] or self::input][1]',
            '//label[contains(.,"tipo da sua solicitação")]/following::*[1]',
            '//*[contains(@class,"select") or @role="combobox"]',
        ]
        aberto = False
        for xpath in triggers:
            try:
                el = WebDriverWait(self.driver, 5).until(EC.element_to_be_clickable((By.XPATH, xpath)))
                self._clicar_js(el)
                aberto = True
                time.sleep(0.8)
                break
            except TimeoutException:
                continue

        opcao_xpaths = [
            f'//*[contains(normalize-space(.),"{self.tipo_solicitacao}")]',
            '//*[contains(normalize-space(.),"Replicação do token API EDI")]',
            '//*[contains(normalize-space(.),"Replicacao do token API EDI")]',
            '//*[contains(normalize-space(.),"já possui um token")]',
            '//*[contains(normalize-space(.),"ja possui um token")]',
        ]
        for xpath in opcao_xpaths:
            try:
                opcoes = self.driver.find_elements(By.XPATH, xpath)
                for op in opcoes:
                    texto = (op.text or '').strip()
                    if not texto:
                        continue
                    if 'Replic' in texto or 'token' in texto.lower():
                        self._clicar_js(op)
                        log.info(f'Tipo de solicitação: {texto[:80]}')
                        time.sleep(0.8)
                        return
            except Exception:
                continue

        if not aberto:
            log.warning('Não foi possível abrir o select de tipo de solicitação')
        else:
            # Digita no combobox se for searchable
            try:
                ativo = self.driver.switch_to.active_element
                ativo.send_keys(self.tipo_solicitacao)
                time.sleep(0.5)
                ativo.send_keys(Keys.ENTER)
                log.info('Tipo selecionado via teclado')
                return
            except Exception:
                pass

        raise Exception('Não foi possível selecionar "Replicação do token API EDI".')

    def _preencher_por_label(
        self,
        labels: list[str],
        valor: str,
        opcional: bool = True,
        multilinha: bool = False,
    ) -> bool:
        if not valor:
            return False

        for label in labels:
            xpaths = [
                f'//label[contains(normalize-space(.),"{label}")]/following::textarea[1]',
                f'//label[contains(normalize-space(.),"{label}")]/following::input[1]',
                f'//*[contains(normalize-space(.),"{label}")]/following::textarea[1]',
                f'//*[contains(normalize-space(.),"{label}")]/following::input[not(@type="hidden")][1]',
                f'//textarea[@placeholder and contains(@placeholder,"{label}")]',
                f'//input[@placeholder and contains(@placeholder,"{label}")]',
            ]
            if multilinha:
                xpaths = [x for x in xpaths if 'textarea' in x] + [x for x in xpaths if 'textarea' not in x]

            for xpath in xpaths:
                try:
                    campos = self.driver.find_elements(By.XPATH, xpath)
                    for campo in campos:
                        if not campo.is_displayed():
                            continue
                        self.driver.execute_script('arguments[0].scrollIntoView({block:"center"});', campo)
                        time.sleep(0.15)
                        try:
                            campo.clear()
                        except Exception:
                            self.driver.execute_script('arguments[0].value = "";', campo)
                        campo.send_keys(valor)
                        log.info(f'Campo "{label}" preenchido')
                        return True
                except Exception:
                    continue

        if opcional:
            log.warning(f'Campo opcional não encontrado: {labels[0]}')
            return False

        raise Exception(f'Campo obrigatório não encontrado: {labels[0]}')

    def _enviar(self) -> str | None:
        botoes = [
            '//button[contains(.,"Create new card")]',
            '//button[contains(.,"Criar")]',
            '//button[contains(.,"Enviar")]',
            '//button[@type="submit"]',
            '//*[contains(@class,"pp-button") and contains(.,"Create")]',
        ]
        for xpath in botoes:
            try:
                btn = WebDriverWait(self.driver, 8).until(EC.element_to_be_clickable((By.XPATH, xpath)))
                self._clicar_js(btn)
                log.info(f'Clicou em enviar: {btn.text}')
                time.sleep(3)
                break
            except TimeoutException:
                continue
        else:
            self._salvar_screenshot('botao_enviar_nao_encontrado')
            raise Exception('Botão de envio do chamado não encontrado.')

        # Tenta capturar ID do card na URL ou na página de sucesso
        card_id = None
        url = self.driver.current_url or ''
        m = re.search(r'/cards?/(\d+)', url)
        if m:
            card_id = m.group(1)
        else:
            try:
                texto = self.driver.find_element(By.TAG_NAME, 'body').text
                m2 = re.search(r'(?:card|chamado|protocolo)\s*[#:.]?\s*(\d{4,})', texto, re.I)
                if m2:
                    card_id = m2.group(1)
            except Exception:
                pass

        # Confirma ausência de erros óbvios no formulário
        try:
            erros = self.driver.find_elements(
                By.XPATH,
                '//*[contains(@class,"error") or contains(@class,"invalid") or contains(@role,"alert")]',
            )
            msgs = [e.text.strip() for e in erros if e.is_displayed() and e.text.strip()]
            if msgs and not card_id:
                raise Exception('Formulário retornou erro: '+'; '.join(msgs[:3]))
        except Exception as e:
            if 'Formulário retornou erro' in str(e):
                raise

        return card_id


def abrir_chamado_pipefy_edi(**kwargs) -> dict:
    return PipefyEdiSolicitador(**kwargs).executar()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Abre chamado Pipefy EDI')
    parser.add_argument('--page-url', default=DEFAULT_PAGE_URL)
    parser.add_argument('--email', required=True)
    parser.add_argument('--token', required=True)
    parser.add_argument('--id-origem', required=True)
    parser.add_argument('--descricao', required=True)
    parser.add_argument('--headless', action='store_true', default=True)
    args = parser.parse_args()
    print(json.dumps(abrir_chamado_pipefy_edi(
        page_url=args.page_url,
        email=args.email,
        token=args.token,
        id_origem=args.id_origem,
        descricao=args.descricao,
        headless=args.headless,
    ), ensure_ascii=False, indent=2))
