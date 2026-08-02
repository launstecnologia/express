# main.py
# Forca de Vendas PagBank — Cadastrar Cliente
# Aceita dados dinâmicos via parametro (uso pela API) ou DADOS_CLI (uso local)
from __future__ import annotations

import json
import re
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
from selenium.common.exceptions import TimeoutException
from webdriver_manager.chrome import ChromeDriverManager

from progresso import reportar as reportar_etapa

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%H:%M:%S',
)
log = logging.getLogger(__name__)

FV_URL = 'https://gestaocomercial.pagbank.com.br/login'

# Incremente a cada deploy — confira em GET /health (campo codigo_versao)
AUTOMACAO_CODIGO_VERSAO = '2026.08.02-proprietario-v5'


class ClienteInternoPagBankError(Exception):
    """Lançada quando o CNPJ/CPF pertence aos times internos do PagBank (FV-CDS-01)."""
    pass


class CadastradorFV:
    """Encapsula toda a lógica de cadastro na Força de Vendas."""

    def __init__(self, dados: dict, fv_usuario: str, fv_senha: str,
                 headless: bool = True, screenshot_dir: str = '/tmp/screenshots',
                 job_id: str | None = None):
        self.dados = dados
        self.fv_usuario = fv_usuario
        self.fv_senha = fv_senha
        self.headless = headless
        self.screenshot_dir = screenshot_dir
        self.job_id = job_id
        self.driver = None
        self.wait = None
        self.screenshots: list[str] = []
        self.etapa_codigo: str | None = None
        self.etapa_label: str | None = None

        os.makedirs(screenshot_dir, exist_ok=True)

    _ETAPA_CODIGOS = {
        'Iniciando navegador...': 'browser',
        'Acessando portal Força de Vendas...': 'login',
        'Abrindo cadastro de cliente...': 'cadastro',
        'Preenchendo dados iniciais...': 'dados_iniciais',
        'Preenchendo dados da empresa (PJ)...': 'dados_empresa',
        'Preenchendo dados do proprietário...': 'dados_proprietario',
        'Preenchendo dados pessoais (PF)...': 'dados_pf',
        'Preenchendo endereço...': 'endereco',
        'Preenchendo segmento...': 'segmento',
        'Preenchendo condições comerciais...': 'condicoes_comerciais',
        'Selecionando plano (promoção mobile)...': 'plano_promocao',
        'Cadastro PagBank concluído': 'concluido',
    }

    def _etapa(self, mensagem: str) -> None:
        self.etapa_label = mensagem.rstrip('.')
        self.etapa_codigo = self._ETAPA_CODIGOS.get(mensagem, self.etapa_codigo)
        reportar_etapa(self.job_id, mensagem)

    # ----------------------------------------------------------------
    # Execucao principal
    # ----------------------------------------------------------------
    def executar(self) -> dict:
        """Executa o fluxo completo. Retorna dict com resultado."""
        try:
            self._etapa('Iniciando navegador...')
            self.driver = self._iniciar_browser()
            self.wait = WebDriverWait(self.driver, 20)

            self._etapa('Acessando portal Força de Vendas...')
            self._fazer_login()
            self._etapa('Abrindo cadastro de cliente...')
            self._navegar_cadastrar_cliente()
            self._etapa('Preenchendo dados iniciais...')
            self._preencher_dados_iniciais()

            tipo = self._detectar_tipo_cliente()

            if tipo == 'pj':
                log.info('>>> Fluxo PESSOA JURÍDICA (CNPJ)')
                self._preparar_dados_pj()
                self._etapa('Preenchendo dados da empresa (PJ)...')
                self._preencher_dados_empresa()
                self._etapa('Preenchendo dados do proprietário...')
                self._preencher_dados_proprietario()
            else:
                log.info('>>> Fluxo PESSOA FÍSICA (CPF)')
                self._etapa('Preenchendo dados pessoais (PF)...')
                self._preencher_dados_pf()

            self._etapa('Preenchendo endereço...')
            self._preencher_endereco()
            self._etapa('Preenchendo segmento...')
            self._preencher_segmento()
            self._etapa('Preenchendo condições comerciais...')
            self._preencher_condicoes_comerciais()

            self._etapa('Cadastro PagBank concluído')
            log.info(f'CADASTRO {tipo.upper()} CONCLUÍDO!')
            return {
                'sucesso': True,
                'tipo': tipo,
                'cpf_cnpj': self.dados['cpf_cnpj'],
                'screenshots': self.screenshots,
            }

        except ClienteInternoPagBankError as e:
            return {
                'sucesso': False,
                'erro': f'CLIENTE_INTERNO: {str(e)}',
                'erro_resumido': str(e),
                'codigo': 'FV-CDS-01',
                'etapa_falha': self.etapa_codigo,
                'etapa_falha_label': self.etapa_label,
                'screenshots': self.screenshots,
            }
        except Exception as e:
            log.error(f'ERRO: {str(e)}')
            if self.driver:
                self._salvar_screenshot('erro_fatal')
            return {
                'sucesso': False,
                'erro': str(e),
                'erro_resumido': self._resumir_erro(str(e)),
                'etapa_falha': self.etapa_codigo,
                'etapa_falha_label': self.etapa_label,
                'screenshots': self.screenshots,
            }
        finally:
            if self.driver:
                self.driver.quit()
                log.info('Browser fechado')

    def executar_consulta_documento(self) -> dict:
        """Consulta CPF/CNPJ no portal FV sem concluir o cadastro."""
        documento = self.dados.get('cpf_cnpj') or self.dados.get('documento') or ''
        digits = re.sub(r'\D', '', documento)
        tipo = 'pj' if len(digits) == 14 else 'pf'

        try:
            self._etapa('Iniciando navegador...')
            self.driver = self._iniciar_browser()
            self.wait = WebDriverWait(self.driver, 20)

            self._etapa('Acessando portal Força de Vendas...')
            self._fazer_login()
            self._etapa('Abrindo cadastro de cliente...')
            self._navegar_cadastrar_cliente()
            self._etapa('Consultando documento no PagBank...')

            campo = self._preencher_react(
                By.XPATH, "//*[@id='document']", documento, 'cpf_cnpj'
            )
            self._disparar_validacao_documento(campo)

            try:
                erros = self._aguardar_validacao_documento(timeout=15)
            except ClienteInternoPagBankError as e:
                msg = str(e).strip() or (
                    'Este cliente é gerenciado pelos times internos do PagBank. '
                    'FV-CDS-01. Não é possível cadastrá-lo pelo portal de parceiros.'
                )
                self._salvar_screenshot('consulta_cliente_interno')
                return {
                    'sucesso': True,
                    'documento': documento,
                    'tipo': tipo,
                    'situacao': 'cliente_interno',
                    'codigo': 'FV-CDS-01',
                    'mensagem': msg,
                    'screenshots': self.screenshots,
                }

            self._salvar_screenshot('consulta_documento')
            return self._resultado_consulta_documento(documento, tipo, erros)

        except Exception as e:
            log.error(f'ERRO consulta documento: {str(e)}')
            if self.driver:
                self._salvar_screenshot('erro_consulta_documento')
            return {
                'sucesso': False,
                'documento': documento,
                'tipo': tipo,
                'erro': str(e),
                'screenshots': self.screenshots,
            }
        finally:
            if self.driver:
                self.driver.quit()
                log.info('Browser fechado')

    def executar_busca_safepay_id(self, email_suffix: str = '@express.app.br') -> dict:
        """Pesquisa cliente no FV e retorna Safepay ID do e-mail @express.app.br."""
        documento = self.dados.get('cpf_cnpj') or self.dados.get('documento') or ''

        try:
            self._etapa('Iniciando navegador...')
            self.driver = self._iniciar_browser()
            self.wait = WebDriverWait(self.driver, 20)

            self._etapa('Acessando portal Força de Vendas...')
            self._fazer_login()
            self._etapa('Abrindo pesquisar clientes...')
            self._navegar_pesquisar_cliente()
            self._etapa('Pesquisando documento no PagBank...')

            encontrado = self._pesquisar_safepay_id(documento, email_suffix)
            self._salvar_screenshot('pesquisa_safepay_resultado')

            if not encontrado:
                return {
                    'sucesso': False,
                    'documento': documento,
                    'erro': f'Safepay ID não encontrado para e-mail {email_suffix}',
                    'screenshots': self.screenshots,
                }

            return {
                'sucesso': True,
                'documento': documento,
                'safepay_id': encontrado['safepay_id'],
                'email_encontrado': encontrado.get('email'),
                'screenshots': self.screenshots,
            }

        except Exception as e:
            log.error(f'ERRO busca Safepay ID: {str(e)}')
            if self.driver:
                self._salvar_screenshot('erro_busca_safepay')
            return {
                'sucesso': False,
                'documento': documento,
                'erro': str(e),
                'screenshots': self.screenshots,
            }
        finally:
            if self.driver:
                self.driver.quit()
                log.info('Browser fechado')

    # ----------------------------------------------------------------
    # Browser
    # ----------------------------------------------------------------
    def _classificar_situacao_documento(self, erros: list[str]) -> str:
        texto = ' '.join(erros).lower()
        if self._texto_e_cliente_interno(texto):
            return 'cliente_interno'
        if any(
            termo in texto
            for termo in (
                'já cadastr',
                'ja cadastr',
                'cadastrado',
                'existente',
                'minha carteira',
                'já possui',
                'ja possui',
                'já existe',
                'ja existe',
            )
        ):
            return 'ja_cadastrado'
        return 'erro_pagbank'

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
            opcoes.add_argument('--headless=new')

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
        caminho = os.path.join(self.screenshot_dir, f'{nome}_{int(time.time())}.png')
        self.driver.save_screenshot(caminho)
        self.screenshots.append(caminho)
        log.info(f'Screenshot: {caminho}')
        return caminho

    # ----------------------------------------------------------------
    # Helpers
    # ----------------------------------------------------------------
    def _verificar_erro_cliente_interno(self):
        for el in self._elementos_erro_documento():
            msg = el.text.strip()
            if self._texto_e_cliente_interno(msg):
                log.error(f'BLOQUEIO PagBank: {msg}')
                raise ClienteInternoPagBankError(msg)

        if self._page_source_indica_cliente_interno():
            msg = (
                'Este cliente é gerenciado pelos times internos do PagBank. '
                'FV-CDS-01'
            )
            log.error(f'BLOQUEIO PagBank (page source): {msg}')
            raise ClienteInternoPagBankError(msg)

    def _texto_e_cliente_interno(self, texto: str) -> bool:
        t = (texto or '').lower()
        return 'fv-cds-01' in t or 'gerenciado pelos times internos' in t

    def _page_source_indica_cliente_interno(self) -> bool:
        html = (self.driver.page_source or '').lower()
        return 'fv-cds-01' in html and 'gerenciado pelos times internos' in html

    def _elementos_erro_documento(self) -> list:
        xpaths = [
            '//*[@data-testid="error-input"]//*[self::h3 or self::p or self::span]',
            '//*[contains(@id,"feedback-title") and contains(@class,"error")]',
            '//*[contains(text(), "FV-CDS-01")]',
            '//*[contains(text(), "gerenciado pelos times internos")]',
        ]
        vistos: set[str] = set()
        elementos = []
        for xpath in xpaths:
            for el in self.driver.find_elements(By.XPATH, xpath):
                try:
                    if not el.is_displayed():
                        continue
                except Exception:
                    continue
                txt = (el.text or '').strip()
                if not txt or txt in vistos:
                    continue
                vistos.add(txt)
                elementos.append(el)
        return elementos

    def _disparar_validacao_documento(self, campo) -> None:
        from selenium.webdriver.common.keys import Keys

        self.driver.execute_script(
            """
            const el = arguments[0];
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            el.dispatchEvent(new Event('blur', { bubbles: true }));
            """,
            campo,
        )
        campo.send_keys(Keys.TAB)
        time.sleep(0.3)

    def _aguardar_validacao_documento(self, timeout: int = 15) -> list[str]:
        """Aguarda a validação assíncrona do CPF/CNPJ no PagBank."""
        deadline = time.time() + timeout
        ultimos_erros: list[str] = []

        while time.time() < deadline:
            try:
                self._verificar_erro_cliente_interno()
            except ClienteInternoPagBankError:
                raise

            ultimos_erros = self._coletar_erros()
            if ultimos_erros:
                return ultimos_erros

            time.sleep(0.75)

        return ultimos_erros

    def _resultado_consulta_documento(self, documento: str, tipo: str, erros: list[str]) -> dict:
        if erros:
            texto = ' '.join(erros)
            if self._texto_e_cliente_interno(texto):
                return {
                    'sucesso': True,
                    'documento': documento,
                    'tipo': tipo,
                    'situacao': 'cliente_interno',
                    'codigo': 'FV-CDS-01',
                    'mensagem': erros[0],
                    'erros': erros,
                    'screenshots': self.screenshots,
                }

            situacao = self._classificar_situacao_documento(erros)
            return {
                'sucesso': True,
                'documento': documento,
                'tipo': tipo,
                'situacao': situacao,
                'mensagem': erros[0],
                'erros': erros,
                'screenshots': self.screenshots,
            }

        return {
            'sucesso': True,
            'documento': documento,
            'tipo': tipo,
            'situacao': 'disponivel',
            'mensagem': 'Documento disponível para cadastro na Força de Vendas.',
            'screenshots': self.screenshots,
        }

    def _clicar(self, by, seletor, descricao=''):
        try:
            el = self.wait.until(EC.element_to_be_clickable((by, seletor)))
            self.driver.execute_script('arguments[0].scrollIntoView(true);', el)
            time.sleep(0.3)
            el.click()
            log.info(f'Clicou: {descricao or seletor}')
            return el
        except TimeoutException:
            self._salvar_screenshot(f'erro_click_{descricao}')
            raise Exception(f'Nao encontrou elemento para clicar: {descricao or seletor}')

    def _clicar_continuar_form(self, descricao: str = 'continuar'):
        """Clica no botão Continuar do formulário FV (XPath fixo quebra entre etapas)."""
        xpaths = [
            '//*[@data-testid="nextButtonFormNavigation"]',
            '//*[@id="__next"]//form//button[contains(normalize-space(.), "Continuar")]',
        ]

        for xpath in xpaths:
            for btn in reversed(self.driver.find_elements(By.XPATH, xpath)):
                try:
                    if not btn.is_displayed():
                        continue
                except Exception:
                    continue

                disabled = (btn.get_attribute('disabled') or '').lower() in ('true', 'disabled')
                aria_disabled = (btn.get_attribute('aria-disabled') or '').lower() == 'true'
                if disabled or aria_disabled:
                    continue

                self.driver.execute_script('arguments[0].scrollIntoView({block: "center"});', btn)
                time.sleep(0.3)
                try:
                    btn.click()
                except Exception:
                    self.driver.execute_script('arguments[0].click();', btn)
                log.info(f'Clicou Continuar ({descricao}) via {xpath}')
                return btn

        self._salvar_screenshot(f'erro_click_{descricao}')
        erros = self._coletar_erros()
        detalhe = ', '.join(erros) if erros else 'Botão Continuar não encontrado ou desabilitado'
        raise Exception(f'Nao encontrou elemento para clicar: {descricao} — {detalhe}')

    @staticmethod
    def _valor_limpo(valor):
        """Normaliza strings descartando lixo como 'null'/'NULL'/'N/A'."""
        texto = (valor or '').strip()
        if texto.lower() in ('null', 'nulo', 'n/a', 'na', '-'):
            return ''
        return texto

    def _preparar_dados_pj(self):
        """Normaliza payload PJ e falha cedo se faltar dado obrigatório."""
        razao = self._valor_limpo(self.dados.get('razao_social'))
        fantasia = self._valor_limpo(self.dados.get('nome_fantasia'))
        if not razao:
            razao = fantasia
        if not fantasia:
            fantasia = razao
        if not razao:
            raise Exception('Razão social não informada no payload da automação.')
        self.dados['nome_fantasia'] = fantasia
        self.dados['razao_social'] = razao

        cel = re.sub(r'\D', '', self.dados.get('celular') or '')
        if len(cel) != 11 or cel[2] != '9':
            raise Exception(
                'Celular inválido no payload: informe DDD + 9 dígitos (ex: 62992777240).'
            )
        self.dados['celular'] = cel

        for campo, label in (
            ('cpf_socio', 'CPF do sócio'),
            ('nascimento', 'Nascimento do sócio'),
            ('nome_socio', 'Nome do sócio'),
        ):
            if not (self.dados.get(campo) or '').strip():
                raise Exception(f'{label} não informado no payload da automação.')

    def _selecionar_faturamento(self):
        faturamento = (self.dados.get('faturamento') or '').strip()
        if not faturamento:
            raise Exception('Faturamento mensal não informado no payload.')

        xpaths_dropdown = [
            '//*[@id="info.monthlyRevenue__label"]/ancestor::div[@role="button"][1]',
            '//*[@data-testid="dropdown-select"][.//*[@id="info.monthlyRevenue__label"]]//div[@role="button"][1]',
            '//*[@data-testid="dropdown-select"]//div[@role="button"][1]',
        ]

        dropdown = None
        for xpath in xpaths_dropdown:
            try:
                dropdown = WebDriverWait(self.driver, 5).until(
                    EC.element_to_be_clickable((By.XPATH, xpath))
                )
                break
            except TimeoutException:
                continue

        if dropdown is None:
            self._salvar_screenshot('erro_faturamento_dropdown')
            raise Exception('Dropdown de faturamento mensal não encontrado no PagBank.')

        self.driver.execute_script('arguments[0].scrollIntoView(true);', dropdown)
        time.sleep(0.3)
        dropdown.click()

        try:
            opcao = self.wait.until(EC.element_to_be_clickable(
                (By.XPATH, f'//li//div[@role="button"][@aria-label="{faturamento}"]')
            ))
        except TimeoutException:
            try:
                opcao = self.wait.until(EC.element_to_be_clickable(
                    (By.XPATH,
                     f'//li//div[@role="button"][contains(@aria-label, "{faturamento[:20]}")]')
                ))
            except TimeoutException:
                self._salvar_screenshot('erro_faturamento_nao_encontrado')
                raise Exception(f'Opção de faturamento não encontrada no PagBank: {faturamento}')

        opcao.click()
        time.sleep(0.5)

    def _configurar_envio_documentos(self):
        """Seleciona envio do link de documentos por e-mail (exigido em cadastros PJ)."""
        email = (self.dados.get('email') or '').strip()
        if not email:
            return

        try:
            secao = self.driver.find_elements(
                By.XPATH,
                '//*[contains(normalize-space(.), "Envio dos documentos")]',
            )
            if not secao:
                return

            marcado = False
            for xpath in (
                '//*[contains(normalize-space(.), "Envio dos documentos")]'
                '/following::*[self::label[contains(normalize-space(.), "E-mail")]'
                ' or self::span[contains(normalize-space(.), "E-mail")]][1]',
                '//*[contains(normalize-space(.), "Envio dos documentos")]'
                '/following::input[@type="checkbox"][1]',
            ):
                for el in self.driver.find_elements(By.XPATH, xpath):
                    try:
                        if not el.is_displayed():
                            continue
                    except Exception:
                        continue

                    self.driver.execute_script(
                        'arguments[0].scrollIntoView({block: "center"});', el
                    )
                    time.sleep(0.2)
                    try:
                        el.click()
                    except Exception:
                        self.driver.execute_script('arguments[0].click();', el)
                    marcado = True
                    log.info('Marcou envio de documentos por e-mail')
                    break
                if marcado:
                    break

            for campo in self.driver.find_elements(
                By.XPATH,
                '//*[contains(normalize-space(.), "Envio dos documentos")]'
                '/following::input[contains(@id, "mail") or @type="email"][1]',
            ):
                try:
                    if not campo.is_displayed():
                        continue
                except Exception:
                    continue

                valor_atual = (campo.get_attribute('value') or '').strip()
                if valor_atual:
                    break

                campo_id = campo.get_attribute('id')
                if campo_id:
                    self._preencher_react(By.ID, campo_id, email, 'email_envio_documentos')
                else:
                    self._limpar_campo_react(campo)
                    campo.send_keys(email)
                log.info(f'Preencheu e-mail para envio de documentos: {email}')
                break
        except Exception as exc:
            log.warning(f'Envio de documentos (opcional): {exc}')

    def _preencher(self, by, seletor, valor, descricao=''):
        try:
            el = self.wait.until(EC.presence_of_element_located((by, seletor)))
            self.driver.execute_script('arguments[0].scrollIntoView(true);', el)
            time.sleep(0.3)
            el.clear()
            el.send_keys(str(valor))
            log.info(f'Preencheu {descricao or seletor}: {valor}')
            return el
        except TimeoutException:
            self._salvar_screenshot(f'erro_preencher_{descricao}')
            raise Exception(f'Nao encontrou campo: {descricao or seletor}')

    def _resolver_input(self, el):
        """Retorna o <input> real quando o seletor aponta para um wrapper React."""
        tag = (el.tag_name or '').lower()
        if tag in ('input', 'textarea'):
            return el
        for seletor in ('input', 'textarea'):
            try:
                inner = el.find_element(By.TAG_NAME, seletor)
                if inner:
                    return inner
            except Exception:
                continue
        return el

    @staticmethod
    def _somente_digitos(valor: str) -> str:
        return re.sub(r'\D', '', valor or '')

    def _ler_valor_input(self, el) -> str:
        el = self._resolver_input(el)
        return (el.get_attribute('value') or '').strip()

    def _campo_preenchido(self, el, esperado: str, apenas_digitos: bool = False) -> bool:
        atual = self._ler_valor_input(el)
        if not atual:
            return False
        if apenas_digitos:
            return len(self._somente_digitos(atual)) >= len(self._somente_digitos(esperado))
        esperado_norm = self._normalizar_texto(esperado)
        atual_norm = self._normalizar_texto(atual)
        return esperado_norm in atual_norm or atual_norm in esperado_norm

    def _set_react_value_js(self, el, valor: str) -> None:
        el = self._resolver_input(el)
        self.driver.execute_script(
            """
            const el = arguments[0];
            const value = arguments[1];
            el.focus();
            const proto = el.tagName === 'TEXTAREA'
                ? HTMLTextAreaElement.prototype
                : HTMLInputElement.prototype;
            const setter = Object.getOwnPropertyDescriptor(proto, 'value')?.set;
            if (setter) {
                setter.call(el, value);
            } else {
                el.value = value;
            }
            for (const type of ['input', 'change', 'blur']) {
                el.dispatchEvent(new Event(type, { bubbles: true }));
            }
            """,
            el,
            str(valor),
        )
        time.sleep(0.35)

    def _digitar_caractere_a_caractere(self, el, texto: str, intervalo: float = 0.06) -> None:
        el = self._resolver_input(el)
        from selenium.webdriver.common.action_chains import ActionChains
        from selenium.webdriver.common.keys import Keys

        ActionChains(self.driver).move_to_element(el).click().perform()
        time.sleep(0.2)
        el.send_keys(Keys.CONTROL, 'a')
        el.send_keys(Keys.BACK_SPACE)
        time.sleep(0.15)
        for char in str(texto):
            el.send_keys(char)
            time.sleep(intervalo)

    def _localizar_campo_fv(self, ids: list[str], labels: list[str] | None = None):
        """Encontra input visível por id, seletor CSS ou texto do label."""
        from selenium.common.exceptions import NoSuchElementException

        labels = labels or []
        for id_ in ids:
            for by, sel in (
                (By.ID, id_),
                (By.CSS_SELECTOR, f'input#{id_}'),
                (By.CSS_SELECTOR, f'input[id*="{id_}"]'),
                (By.CSS_SELECTOR, f'[id="{id_}"] input'),
            ):
                try:
                    for el in self.driver.find_elements(by, sel):
                        try:
                            if not el.is_displayed():
                                continue
                        except Exception:
                            continue
                        return el
                except Exception:
                    continue

        for label in labels:
            xpaths = (
                f'//label[contains(normalize-space(.), "{label}")]/following::input[1]',
                f'//label[contains(normalize-space(.), "{label}")]/..//input',
                f'//input[@placeholder="{label}"]',
                f'//*[contains(normalize-space(.), "{label}")]/ancestor::div[contains(@class,"input") or @role="group"][1]//input',
            )
            for xpath in xpaths:
                try:
                    el = self.driver.find_element(By.XPATH, xpath)
                    if el.is_displayed():
                        return el
                except Exception:
                    continue

        raise NoSuchElementException(
            f'Campo FV não encontrado (ids={ids}, labels={labels})'
        )

    def _salvar_diagnostico_formulario(self, contexto: str) -> str:
        """Salva HTML + lista de inputs visíveis para debug."""
        stamp = int(time.time())
        html_path = os.path.join(self.screenshot_dir, f'diag_{contexto}_{stamp}.html')
        txt_path = os.path.join(self.screenshot_dir, f'diag_{contexto}_{stamp}.txt')

        with open(html_path, 'w', encoding='utf-8') as fh:
            fh.write(self.driver.page_source)

        linhas = [f'=== Diagnóstico {contexto} — v{AUTOMACAO_CODIGO_VERSAO} ===', '']
        for inp in self.driver.find_elements(By.CSS_SELECTOR, 'input, textarea, select'):
            try:
                if not inp.is_displayed():
                    continue
            except Exception:
                continue
            linhas.append(
                ' | '.join([
                    f'id={inp.get_attribute("id")!r}',
                    f'name={inp.get_attribute("name")!r}',
                    f'type={inp.get_attribute("type")!r}',
                    f'value={inp.get_attribute("value")!r}',
                    f'placeholder={inp.get_attribute("placeholder")!r}',
                ])
            )

        with open(txt_path, 'w', encoding='utf-8') as fh:
            fh.write('\n'.join(linhas))

        log.warning(f'Diagnóstico salvo: {txt_path}')
        self._salvar_screenshot(f'diag_{contexto}')
        return txt_path

    def _preencher_campo_fv_elemento(
        self,
        el,
        valor: str,
        descricao: str = '',
        *,
        apenas_digitos: bool = False,
        variantes: list[str] | None = None,
    ):
        """Preenche um elemento já localizado."""
        if not (valor or '').strip():
            raise Exception(f'Valor vazio para campo: {descricao}')

        self.driver.execute_script('arguments[0].scrollIntoView({block: "center"});', el)
        time.sleep(0.25)

        tentativas = list(dict.fromkeys(variantes or [valor]))
        if apenas_digitos:
            digits = self._somente_digitos(valor)
            tentativas = list(dict.fromkeys([digits, valor] + tentativas))

        ultimo_erro = None
        for tentativa in tentativas:
            if not tentativa:
                continue
            try:
                alvo = self._resolver_input(el)
                self._set_react_value_js(alvo, tentativa)
                if self._campo_preenchido(el, valor, apenas_digitos=apenas_digitos):
                    log.info(f'Preencheu (js) {descricao}: {tentativa}')
                    return el

                self._digitar_caractere_a_caractere(alvo, tentativa)
                if self._campo_preenchido(el, valor, apenas_digitos=apenas_digitos):
                    log.info(f'Preencheu (digitação) {descricao}: {tentativa}')
                    return el
            except Exception as exc:
                ultimo_erro = exc
                log.warning(f'Tentativa falhou em {descricao}: {exc}')

        self._salvar_diagnostico_formulario(descricao or 'campo')
        self._salvar_screenshot(f'erro_preencher_{descricao}')
        detalhe = f'Campo permaneceu vazio (esperado: {valor})'
        if ultimo_erro:
            detalhe = f'{detalhe} — {ultimo_erro}'
        raise Exception(f'Nao preencheu campo: {descricao} — {detalhe}')

    def _preencher_campo_fv(
        self,
        by,
        seletor: str,
        valor: str,
        descricao: str = '',
        *,
        ids: list[str] | None = None,
        labels: list[str] | None = None,
        apenas_digitos: bool = False,
        variantes: list[str] | None = None,
    ):
        """Preenche campo React do FV com JS + digitação lenta e valida o resultado."""
        try:
            el = self._localizar_campo_fv(ids or [seletor], labels)
        except Exception:
            el = self.wait.until(EC.visibility_of_element_located((by, seletor)))

        return self._preencher_campo_fv_elemento(
            el,
            valor,
            descricao or seletor,
            apenas_digitos=apenas_digitos,
            variantes=variantes,
        )

    def _limpar_campo_react(self, el) -> None:
        """Limpa input React sem usar clear() — evita travamento do ChromeDriver em máscaras."""
        from selenium.webdriver.common.keys import Keys

        try:
            el.click()
            time.sleep(0.15)
            el.send_keys(Keys.CONTROL, 'a')
            el.send_keys(Keys.BACK_SPACE)
            time.sleep(0.2)
        except Exception:
            self.driver.execute_script(
                "const el = arguments[0];"
                "el.value = '';"
                "el.dispatchEvent(new Event('input', { bubbles: true }));"
                "el.dispatchEvent(new Event('change', { bubbles: true }));",
                el,
            )
            time.sleep(0.2)

    def _preencher_react(self, by, seletor, valor, descricao=''):
        """Preenche campo React disparando evento de input corretamente.
        Simula digitação humana: preenche, apaga última letra e redigita
        para forçar o React a revalidar o campo."""
        from selenium.webdriver.common.keys import Keys
        try:
            el = self.wait.until(EC.element_to_be_clickable((by, seletor)))
            self.driver.execute_script('arguments[0].scrollIntoView(true);', el)
            time.sleep(0.3)
            self._limpar_campo_react(el)
            el.send_keys(str(valor))
            time.sleep(0.3)
            if valor:
                # Apaga última letra e redigita para disparar validação React
                el.send_keys(Keys.BACK_SPACE)
                time.sleep(0.2)
                el.send_keys(str(valor)[-1])
            time.sleep(0.3)
            log.info(f'Preencheu (react) {descricao or seletor}: {valor}')
            return el
        except TimeoutException:
            self._salvar_screenshot(f'erro_preencher_{descricao}')
            raise Exception(f'Nao encontrou campo: {descricao or seletor}')

    def _redigitar_ultimo_char(self, campo, valor: str):
        """Apaga o último caractere e redigita para forçar revalidação do React."""
        from selenium.webdriver.common.keys import Keys
        if not valor:
            return
        campo.send_keys(Keys.END)
        time.sleep(0.2)
        campo.send_keys(Keys.BACK_SPACE)
        time.sleep(0.3)
        campo.send_keys(valor[-1])
        time.sleep(0.3)

    def _normalizar_texto(self, texto: str) -> str:
        import unicodedata

        t = unicodedata.normalize('NFKD', (texto or '').strip())

        return t.encode('ascii', 'ignore').decode().lower()

    def _texto_e_erro_validacao(self, texto: str) -> bool:
        tl = self._normalizar_texto(texto)
        if not tl:
            return False

        ignorar = (
            'cadastro pessoa juridica',
            'cadastrar pessoa juridica',
            'cadastrar pessoa fisica',
            'dados da empresa e contato',
        )
        if tl in ignorar:
            return False

        # Cabeçalho/breadcrumb do portal FV — não é erro de campo
        if 'forca de vendas' in tl and ('cadastro pessoa' in tl or 'cadastrar pessoa' in tl):
            return False

        if tl.startswith('pagbank') and 'forca de vendas' in tl:
            return False

        return True

    def _texto_indica_erro_campo(self, texto: str) -> bool:
        tl = self._normalizar_texto(texto)
        if not tl or not self._texto_e_erro_validacao(texto):
            return False

        indicadores = (
            'invalido',
            'obrigator',
            'similar',
            'fv-cds',
            'gerenciado pelos times',
            'nao e possivel',
            'informe',
            'preencha',
            'required',
            'erro ao',
            'nao foi possivel',
        )

        return any(ind in tl for ind in indicadores)

    @staticmethod
    def _erro_de_nome_fantasia(texto: str) -> bool:
        t = (texto or '').lower()
        return 'fantasia' in t or 'similar' in t or 'trademark' in t

    def _exigir_erros_reais(self, contexto: str, erros: list[str] | None = None):
        erros = erros if erros is not None else self._coletar_erros()
        reais = [e for e in erros if self._texto_indica_erro_campo(e)]
        if reais:
            self._salvar_screenshot(f'erro_validacao_{contexto}')
            raise Exception(f'Validação PagBank ({contexto}): {", ".join(reais)}')

    def _formatar_celular_br(self, digits: str) -> str:
        d = re.sub(r'\D', '', digits or '')
        if len(d) == 11:
            return f'({d[:2]}) {d[2:7]}-{d[7:]}'
        if len(d) == 10:
            return f'({d[:2]}) {d[2:6]}-{d[6:]}'

        return d

    def _coletar_erros(self):
        msgs: list[str] = []
        vistos: set[str] = set()

        for el in self._elementos_erro_documento():
            txt = el.text.strip()
            if txt and txt not in vistos and self._texto_e_erro_validacao(txt):
                vistos.add(txt)
                msgs.append(txt)

        if msgs:
            log.warning(f'Erros de validacao ({len(msgs)}): {msgs}')
        return msgs

    def _limpar_telefone_fixo(self):
        """Remove telefone fixo — PagBank pré-preenche via CNPJ e costuma invalidar."""
        try:
            from selenium.webdriver.common.keys import Keys
            campo = self.driver.find_element(By.ID, 'info.formattedPhone')
            valor = (campo.get_attribute('value') or '').strip()
            if not valor:
                return
            self.driver.execute_script('arguments[0].scrollIntoView(true);', campo)
            campo.click()
            time.sleep(0.2)
            campo.send_keys(Keys.CONTROL, 'a')
            campo.send_keys(Keys.BACK_SPACE)
            time.sleep(0.3)
            log.info(f'Telefone fixo ignorado (removido valor: {valor})')
        except Exception:
            pass

    def _exigir_sem_erros(self, contexto: str):
        erros = self._coletar_erros()
        if erros:
            self._salvar_screenshot(f'erro_validacao_{contexto}')
            raise Exception(f'Validação PagBank ({contexto}): {", ".join(erros)}')

    def _aguardar_campo_ou_falhar(self, by, seletor: str, contexto: str, timeout: int = 25):
        try:
            WebDriverWait(self.driver, timeout).until(
                EC.visibility_of_element_located((by, seletor))
            )
        except TimeoutException:
            erros = self._coletar_erros()
            self._salvar_screenshot(f'timeout_{contexto}')
            detalhe = ', '.join(erros) if erros else f'Campo {seletor} não apareceu'
            raise Exception(f'PagBank não avançou ({contexto}): {detalhe}')

    @staticmethod
    def _resumir_erro(erro: str) -> str:
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

    # ----------------------------------------------------------------
    # Etapas
    # ----------------------------------------------------------------
    def _fazer_login(self):
        log.info('--- ETAPA 1: LOGIN ---')
        self.driver.get(FV_URL)
        time.sleep(2)

        try:
            radio = self.wait.until(EC.presence_of_element_located(
                (By.XPATH, "//label[contains(text(), 'Parceiro')]")
            ))
            radio.click()
            log.info('Selecionou: Parceiro(a) PagBank')
        except TimeoutException:
            radios = self.driver.find_elements(By.XPATH, "//input[@type='radio']")
            if len(radios) >= 2:
                radios[1].click()

        time.sleep(0.5)

        self._preencher(
            By.XPATH,
            "//input[@placeholder='Usuário' or @name='username' or @id='username']",
            self.fv_usuario, 'usuario',
        )
        self._preencher(
            By.XPATH,
            "//input[@placeholder='Senha' or @name='password' or @id='password' or @type='password']",
            self.fv_senha, 'senha',
        )
        time.sleep(0.5)
        self._clicar(
            By.XPATH,
            "//button[contains(text(), 'Entrar')] | //input[@value='Entrar']",
            'botao_entrar',
        )

        try:
            self.wait.until(EC.any_of(
                EC.presence_of_element_located((By.XPATH, "//*[contains(text(), 'MINHA CARTEIRA')]")),
                EC.presence_of_element_located((By.XPATH, "//*[contains(text(), 'PÁGINA INICIAL')]")),
                EC.url_contains('gestaocomercial.pagbank.com.br'),
            ))
            log.info('Login realizado com sucesso')
        except TimeoutException:
            self._salvar_screenshot('erro_pos_login')
            raise Exception('Dashboard nao carregou apos o login')

        time.sleep(1)

    def _navegar_cadastrar_cliente(self):
        log.info('--- ETAPA 2: NAVEGAR PARA CADASTRAR CLIENTE ---')
        self._clicar(By.XPATH, "//*[@id='menu']/li[3]/a/div[2]/span", 'menu_minha_carteira')
        time.sleep(1)
        self._clicar(By.XPATH, "//*[@id='registerCreate']/div/span", 'menu_cadastrar_cliente')
        self.wait.until(EC.presence_of_element_located(
            (By.XPATH, "//*[contains(text(), 'Cadastrar cliente') and not(contains(@class, 'menu'))]"
                       " | //input[@placeholder='CPF/CNPJ']")
        ))
        log.info('Pagina Cadastrar Cliente carregada')
        time.sleep(1)

    def _navegar_pesquisar_cliente(self):
        log.info('--- NAVEGAR PARA PESQUISAR CLIENTE ---')
        self._clicar(By.XPATH, "//*[@id='menu']/li[3]/a/div[2]/span", 'menu_minha_carteira')
        time.sleep(1)
        self._clicar(By.XPATH, '//*[@id="customer-search"]/div/span', 'menu_pesquisar_cliente')
        self.wait.until(EC.presence_of_element_located((By.ID, 'document')))
        log.info('Pagina Pesquisar Cliente carregada')
        time.sleep(1)

    def _pesquisar_safepay_id(self, documento: str, email_suffix: str = '@express.app.br') -> dict | None:
        self._selecionar_filtro_documento()
        campo = self._preencher_react(By.ID, 'document', documento, 'documento_pesquisa')
        self._disparar_validacao_documento(campo)
        self._clicar(
            By.XPATH,
            '//*[@id="__next"]/div/main/div/div/div[2]/form/div[1]/div/div/button',
            'botao_pesquisar_cliente',
        )
        time.sleep(2)
        try:
            self.wait.until(EC.presence_of_element_located(
                (By.XPATH, '//*[contains(text(), "Safepay ID")] | //*[contains(text(), "Clientes")]')
            ))
        except TimeoutException:
            log.warning('Resultados da pesquisa demoraram — tentando extrair mesmo assim')
        time.sleep(1)
        return self._extrair_safepay_id_express(email_suffix)

    def _selecionar_filtro_documento(self):
        for xpath in (
            '//input[@type="radio" and (@value="document" or contains(@id, "document"))]',
            '//label[contains(., "CPF") and contains(., "CNPJ")]//input[@type="radio"]',
            '//label[contains(., "CPF / CNPJ")]',
        ):
            try:
                el = self.driver.find_element(By.XPATH, xpath)
                if el.tag_name.lower() == 'label':
                    el.click()
                else:
                    self.driver.execute_script('arguments[0].click();', el)
                time.sleep(0.3)
                log.info('Filtro CPF/CNPJ selecionado')
                return
            except Exception:
                continue

    def _extrair_safepay_id_express(self, email_suffix: str = '@express.app.br') -> dict | None:
        suffix = email_suffix.lower().lstrip('@')
        padrao_email = re.compile(r'[\w.+-]+@' + re.escape(suffix), re.IGNORECASE)
        padrao_safepay = re.compile(r'Safepay ID\s*\n?\s*(\d+)', re.IGNORECASE)

        candidatos = self.driver.find_elements(
            By.XPATH,
            '//*[contains(translate(text(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), '
            f'"{suffix}")]',
        )

        for el in candidatos:
            try:
                if not el.is_displayed():
                    continue
                card = el.find_element(
                    By.XPATH,
                    './ancestor::div[.//*[contains(text(), "Safepay ID")]][1]',
                )
                texto = card.text
                if suffix not in texto.lower():
                    continue
                id_match = padrao_safepay.search(texto)
                if not id_match:
                    continue
                email_match = padrao_email.search(texto)
                return {
                    'safepay_id': id_match.group(1),
                    'email': email_match.group(0) if email_match else '',
                }
            except Exception:
                continue

        body = self.driver.find_element(By.TAG_NAME, 'body').text
        blocos = re.split(r'\n\s*\n', body)
        for bloco in blocos:
            bloco_lower = bloco.lower()
            if 'safepay id' not in bloco_lower or suffix not in bloco_lower:
                continue
            id_match = padrao_safepay.search(bloco)
            email_match = padrao_email.search(bloco)
            if id_match:
                return {
                    'safepay_id': id_match.group(1),
                    'email': email_match.group(0) if email_match else '',
                }

        return None

    def _preencher_dados_iniciais(self):
        log.info('--- ETAPA 3: CPF/CNPJ E EMAIL ---')
        campo = self._preencher_react(
            By.XPATH, "//*[@id='document']", self.dados['cpf_cnpj'], 'cpf_cnpj'
        )
        self._disparar_validacao_documento(campo)
        erros_doc = self._aguardar_validacao_documento(timeout=12)
        if erros_doc:
            self._exigir_sem_erros('documento')
        self._verificar_erro_cliente_interno()

        self._preencher(
            By.XPATH,
            "//*[@id='__next']/div/main/div/div/form/div/div[2]/div[2]/div/div/input",
            self.dados['email'], 'email',
        )
        time.sleep(0.5)
        self._preencher(
            By.XPATH,
            "//*[@id='__next']/div/main/div/div/form/div/div[2]/div[3]/div/div/input",
            self.dados['email_confirmar'], 'email_confirmar',
        )
        time.sleep(0.5)
        self._clicar(By.XPATH, "//*[@id='__next']/div/main/div/div/form/button", 'botao_continuar')
        log.info('Clicou em Continuar — aguardando proxima tela...')
        time.sleep(3)
        self._salvar_screenshot('etapa1_pos_email')

    def _detectar_tipo_cliente(self) -> str:
        try:
            self.wait.until(EC.presence_of_element_located(
                (By.XPATH, '//*[contains(text(), "Cadastrar pessoa")]')
            ))
            titulo = self.driver.find_element(
                By.XPATH, '//*[contains(text(), "Cadastrar pessoa")]'
            ).text.lower()
            if 'física' in titulo or 'fisica' in titulo:
                return 'pf'
            return 'pj'
        except TimeoutException:
            return 'pj' if '/' in self.dados['cpf_cnpj'] else 'pf'

    def _preencher_dados_empresa(self):
        from selenium.webdriver.common.keys import Keys

        log.info('--- ETAPA 4: DADOS DA EMPRESA ---')
        self.wait.until(EC.presence_of_element_located((By.ID, 'info.companyName')))
        time.sleep(1)

        # Razão social normalmente vem pré-preenchida pelo PagBank via CNPJ, mas
        # às vezes o próprio Força de Vendas devolve o campo vazio ou com o texto
        # literal "null". Nesses casos, preenchemos com a razão social que já está
        # cadastrada na plataforma.
        campo = self.driver.find_element(By.ID, 'info.companyName')
        valor_atual = self._valor_limpo(campo.get_attribute('value'))
        razao_plataforma = self._valor_limpo(self.dados.get('razao_social'))
        self.driver.execute_script('arguments[0].scrollIntoView(true);', campo)
        campo.click()
        time.sleep(0.3)

        if not valor_atual and razao_plataforma:
            log.warning(
                f'Razão social ausente/"null" no FV — preenchendo com a da plataforma: {razao_plataforma}'
            )
            campo.send_keys(Keys.CONTROL, 'a')
            campo.send_keys(Keys.BACK_SPACE)
            time.sleep(0.3)
            campo.send_keys(razao_plataforma)
            time.sleep(0.3)
            self._redigitar_ultimo_char(campo, razao_plataforma)
        else:
            self._redigitar_ultimo_char(campo, razao_plataforma or valor_atual)

        # Nome fantasia: preenche e força revalidação do React com backspace+redigitar
        campo_fantasia = self.wait.until(EC.presence_of_element_located((By.ID, 'info.trademark')))
        self.driver.execute_script('arguments[0].scrollIntoView(true);', campo_fantasia)
        self._limpar_campo_react(campo_fantasia)
        time.sleep(0.3)
        campo_fantasia.send_keys(self.dados['nome_fantasia'])
        time.sleep(0.5)
        self._redigitar_ultimo_char(campo_fantasia, self.dados['nome_fantasia'])
        log.info(f'Preencheu nome_fantasia: {self.dados["nome_fantasia"]}')

        cel = re.sub(r'\D', '', self.dados.get('celular') or '')
        if not cel:
            raise Exception('Celular não informado — obrigatório na etapa de dados da empresa.')

        # Telefone fixo: sempre ignorar (nunca preencher; limpa se PagBank pré-preencheu)
        self._limpar_telefone_fixo()

        self._preencher_react(
            By.ID,
            'info.formattedCelphone',
            self._formatar_celular_br(cel),
            'celular',
        )

        if self.dados.get('url_site'):
            self._preencher_react(By.ID, 'info.websiteURL', self.dados['url_site'], 'url_site')

        time.sleep(1)
        erros = self._coletar_erros()

        if any('número inválido' in (e or '').lower() or 'numero invalido' in (e or '').lower() for e in erros):
            log.warning('Erro de telefone — limpando fixo novamente')
            self._limpar_telefone_fixo()
            time.sleep(0.5)
            erros = self._coletar_erros()

        # Segue sempre o nome fantasia cadastrado no sistema. O PagBank pode
        # acusar "não similar à razão social", mas por decisão do negócio NÃO
        # trocamos pela razão — apenas ignoramos esse aviso para não travar o fluxo.
        erros_fantasia = [e for e in erros if self._erro_de_nome_fantasia(e)]
        if erros_fantasia:
            log.warning(f'Aviso de nome fantasia ignorado (mantém o do sistema): {erros_fantasia}')

        erros = [e for e in erros if not self._erro_de_nome_fantasia(e)]

        self._exigir_erros_reais('dados da empresa', erros)

        self._clicar_continuar_form('botao_continuar_etapa2')
        time.sleep(2)
        self._aguardar_campo_ou_falhar(By.ID, 'info.cpf', 'dados do proprietário')
        self._salvar_screenshot('etapa2_dados_empresa_preenchido')

    def _preencher_dados_proprietario(self):
        log.info('--- ETAPA 5: DADOS DO PROPRIETARIO ---')
        self._aguardar_campo_ou_falhar(By.ID, 'info.cpf', 'dados do proprietário', timeout=15)
        time.sleep(1)

        cpf = self.dados['cpf_socio']
        nascimento = self.dados['nascimento']
        nome = self.dados['nome_socio']

        self._preencher_campo_fv(
            By.ID, 'info.cpf', cpf, 'cpf_socio',
            ids=['info.cpf'],
            labels=['CPF'],
            apenas_digitos=True,
            variantes=[self._somente_digitos(cpf), cpf],
        )
        time.sleep(0.4)

        self._preencher_campo_fv(
            By.ID, 'info.birthDate', nascimento, 'nascimento',
            ids=['info.birthDate'],
            labels=['Data de nascimento', 'Nascimento'],
            apenas_digitos=True,
            variantes=[self._somente_digitos(nascimento), nascimento],
        )
        time.sleep(0.4)

        self._preencher_campo_fv(
            By.ID, 'info.name', nome, 'nome_socio',
            ids=['info.name'],
            labels=['Nome completo', 'Nome'],
        )
        time.sleep(0.4)

        self._selecionar_faturamento()
        self._configurar_envio_documentos()

        erros = self._coletar_erros()
        self._exigir_erros_reais('dados do proprietário', erros)
        self._salvar_screenshot('etapa3_proprietario')
        self._clicar_continuar_form('botao_continuar_etapa3')
        time.sleep(3)
        self._coletar_erros()
        self._salvar_screenshot('etapa4_endereco')

    def _preencher_dados_pf(self):
        log.info('--- ETAPA PF-1: DADOS PESSOAIS E DE CONTATO ---')
        self.wait.until(EC.presence_of_element_located((By.ID, 'info.name')))
        time.sleep(0.5)

        dropdown = self.wait.until(EC.element_to_be_clickable(
            (By.XPATH, '//*[@id="info.monthlyRevenue__label"]/ancestor::div[@role="button"][1]')
        ))
        self.driver.execute_script('arguments[0].scrollIntoView(true);', dropdown)
        time.sleep(0.3)
        dropdown.click()

        opcao = self.wait.until(EC.element_to_be_clickable(
            (By.XPATH, f'//li//div[@role="button"][@aria-label="{self.dados["faturamento"]}"]')
        ))
        opcao.click()
        time.sleep(0.5)

        campo_cel = self.wait.until(EC.presence_of_element_located((By.ID, 'info.formattedCelphone')))
        self.driver.execute_script('arguments[0].scrollIntoView(true);', campo_cel)
        campo_cel.clear()
        time.sleep(0.3)
        cel = re.sub(r'\D', '', self.dados.get('celular') or '')
        campo_cel.send_keys(cel)

        self._limpar_telefone_fixo()

        if self.dados.get('url_site'):
            self._preencher(By.ID, 'info.websiteURL', self.dados['url_site'], 'url_site')

        time.sleep(0.5)
        self._coletar_erros()
        self._clicar(
            By.XPATH, '//*[@data-testid="nextButtonFormNavigation"]', 'botao_continuar_pf1'
        )
        time.sleep(3)
        self._coletar_erros()
        self._salvar_screenshot('pf_etapa2_endereco')

    def _preencher_endereco(self):
        log.info('--- ETAPA: ENDERECO ---')
        from selenium.webdriver.support.ui import Select

        self.wait.until(EC.presence_of_element_located((By.ID, 'address.postalCode')))
        time.sleep(1)

        self._preencher(By.ID, 'address.postalCode', self.dados['cep'], 'cep')

        try:
            self.wait.until(lambda d: d.find_element(By.ID, 'address.city').get_attribute('value') != '')
        except TimeoutException:
            log.warning('API do CEP nao respondeu a tempo')

        time.sleep(0.5)

        campo_end = self.driver.find_element(By.ID, 'address.address')
        if not campo_end.get_attribute('value'):
            if self.dados.get('endereco'):
                self._preencher(By.ID, 'address.address', self.dados['endereco'], 'endereco')
            if self.dados.get('bairro'):
                self._preencher(By.ID, 'address.districtName', self.dados['bairro'], 'bairro')

        time.sleep(0.5)
        self._preencher(By.ID, 'address.addressNumber', self.dados['numero'], 'numero')

        if self.dados.get('complemento'):
            self._preencher(By.ID, 'address.addressComplement', self.dados['complemento'], 'complemento')

        try:
            select_estado = Select(self.driver.find_element(By.ID, 'address.federationUnit'))
            if not select_estado.first_selected_option.get_attribute('value') and self.dados.get('estado'):
                select_estado.select_by_value(self.dados['estado'])
        except Exception as e:
            log.warning(f'Estado: {e}')

        time.sleep(0.5)
        self._coletar_erros()
        self._clicar(
            By.XPATH,
            "//*[@id='__next']/div/main/div/div/form/div[2]/div/button[2]",
            'botao_continuar_endereco',
        )
        time.sleep(3)
        self._coletar_erros()
        self._salvar_screenshot('etapa5_endereco')

    def _preencher_segmento(self):
        log.info('--- ETAPA: SEGMENTO ---')
        self.wait.until(EC.presence_of_element_located(
            (By.ID, 'business.productMainCategoryId__label')
        ))
        time.sleep(1)

        dropdown = self.wait.until(EC.element_to_be_clickable(
            (By.XPATH, '//*[@id="business.productMainCategoryId__label"]/ancestor::div[@role="button"][1]')
        ))
        self.driver.execute_script('arguments[0].scrollIntoView(true);', dropdown)
        time.sleep(0.3)
        dropdown.click()

        opcao = self.wait.until(EC.element_to_be_clickable(
            (By.XPATH, f'//li//div[@role="button"][@aria-label="{self.dados["segmento"]}"]')
        ))
        opcao.click()

        time.sleep(0.5)
        self._coletar_erros()
        self._clicar(
            By.XPATH,
            "//*[@id='__next']/div/main/div/div/form/div[2]/div/button[2]",
            'botao_continuar_segmento',
        )
        time.sleep(3)
        self._coletar_erros()
        self._salvar_screenshot('etapa6_segmento')

    def _preencher_condicoes_comerciais(self):
        log.info('--- ETAPA: CONDICOES COMERCIAIS ---')
        self._aguardar_campo_ou_falhar(
            By.XPATH,
            '//*[@data-testid="btn_linkMobile"]',
            'condicoes_comerciais',
            timeout=25,
        )
        time.sleep(1)

        testid_map = {
            'Link Web': 'btn_linkWeb',
            'Link Mobile': 'btn_linkMobile',
            'Link Híbrido': 'btn_linkHybrid',
        }
        testid = testid_map.get(self.dados.get('tipo_link', 'Link Mobile'), 'btn_linkMobile')
        chip = self.driver.find_element(By.XPATH, f'//*[@data-testid="{testid}"]')
        if 'chips_chipsSelected' not in chip.get_attribute('class') and not chip.get_attribute('disabled'):
            self.driver.execute_script('arguments[0].click();', chip)

        time.sleep(0.5)
        self._etapa('Selecionando plano (promoção mobile)...')

        self._aguardar_campo_ou_falhar(
            By.XPATH,
            '//*[@data-testid="promotion-mobile-select"]//*[@role="button"][1]',
            'plano_promocao',
            timeout=25,
        )

        dropdown = self.driver.find_element(
            By.XPATH, '//*[@data-testid="promotion-mobile-select"]//*[@role="button"][1]'
        )
        self.driver.execute_script('arguments[0].scrollIntoView(true);', dropdown)
        time.sleep(0.3)
        dropdown.click()

        promocao = self.dados.get('promocao')
        try:
            if promocao:
                opcao = self.wait.until(EC.element_to_be_clickable(
                    (By.XPATH, f'//li//div[@role="button"][@aria-label="{promocao}"]')
                ))
                opcao.click()
            else:
                primeira = self.wait.until(EC.element_to_be_clickable(
                    (By.XPATH,
                     '//*[@data-testid="promotion-mobile-select"]'
                     '//li//div[@role="button" and contains(@class,"styles_item")]')
                ))
                primeira.click()
        except TimeoutException:
            self._salvar_screenshot('timeout_plano_promocao')
            codigo = promocao or 'primeira opção da lista'
            raise Exception(
                f'PagBank: plano/promoção "{codigo}" não encontrado ou não pôde ser selecionado '
                f'no campo "Promoção mobile".'
            )

        self._salvar_screenshot('etapa_plano_promocao')

        self.wait.until(EC.element_to_be_clickable(
            (By.XPATH, '//*[@data-testid="nextButtonFormNavigation" and not(@disabled)]')
        ))
        self._coletar_erros()
        self._clicar(
            By.XPATH, '//*[@data-testid="nextButtonFormNavigation"]', 'botao_continuar_condicoes'
        )
        time.sleep(3)
        self._coletar_erros()
        self._salvar_screenshot('etapa_confirmacao')

        log.info('--- ETAPA FINAL: CONFIRMACAO ---')
        try:
            self._clicar(
                By.XPATH,
                "//*[@id='__next']/div/main/div/div/form/div[6]/div/button[2]",
                'botao_confirmar_cadastro',
            )
            log.info('Cadastro confirmado!')
            time.sleep(3)
            self._salvar_screenshot('cadastro_concluido')
        except Exception:
            log.warning('Botao de confirmacao nao encontrado — pode ja ter avancado')
            self._salvar_screenshot('pos_confirmacao')


# ----------------------------------------------------------------
# Funcao publica — usada pela API
# ----------------------------------------------------------------
def consultar_documento_fv(documento: str, fv_usuario: str, fv_senha: str,
                           headless: bool = True, screenshot_dir: str = '/tmp/screenshots',
                           job_id: str | None = None) -> dict:
    """Consulta CPF/CNPJ no portal FV sem concluir cadastro."""
    cadastrador = CadastradorFV(
        dados={'cpf_cnpj': documento},
        fv_usuario=fv_usuario,
        fv_senha=fv_senha,
        headless=headless,
        screenshot_dir=screenshot_dir,
        job_id=job_id,
    )
    return cadastrador.executar_consulta_documento()


def buscar_safepay_id_fv(documento: str, fv_usuario: str, fv_senha: str,
                         email_suffix: str = '@express.app.br',
                         headless: bool = True, screenshot_dir: str = '/tmp/screenshots',
                         job_id: str | None = None) -> dict:
    """Pesquisa cliente no FV e retorna Safepay ID do e-mail @express.app.br."""
    cadastrador = CadastradorFV(
        dados={'cpf_cnpj': documento},
        fv_usuario=fv_usuario,
        fv_senha=fv_senha,
        headless=headless,
        screenshot_dir=screenshot_dir,
        job_id=job_id,
    )
    return cadastrador.executar_busca_safepay_id(email_suffix=email_suffix)


def cadastrar_fv(dados: dict, fv_usuario: str, fv_senha: str,
                 headless: bool = True, screenshot_dir: str = '/tmp/screenshots',
                 job_id: str | None = None) -> dict:
    """
    Ponto de entrada publico para a API FastAPI.
    Retorna dict com chaves: sucesso, tipo, cpf_cnpj, screenshots, erro (se falhou).
    """
    cadastrador = CadastradorFV(
        dados=dados,
        fv_usuario=fv_usuario,
        fv_senha=fv_senha,
        headless=headless,
        screenshot_dir=screenshot_dir,
        job_id=job_id,
    )
    return cadastrador.executar()


# ----------------------------------------------------------------
# CLI — uso local para testes
# ----------------------------------------------------------------
DADOS_CLI = {
    'cpf_cnpj':        '018.118.831-76',
    'email':           'cv@expresspag.com.br',
    'email_confirmar': 'cv@expresspag.com.br',
    'celular':         '89981254658',
    'telefone':        '',
    'url_site':        '',
    'faturamento':     'De R$ 1 mil até R$ 5 mil',
    'cep':             '64993-000',
    'endereco':        'Av São Gonçalo',
    'bairro':          'Centro',
    'numero':          '10',
    'complemento':     '',
    'estado':          '',
    'segmento':        'Outras atividades empresariais',
    'tipo_link':       'Link Mobile',
    'promocao':        'nnexpresspay7399d028retorno',
    'razao_social':    'L. R. DE MORAES ONLINE TECNOLOGIA LTDA',
    'nome_fantasia':   'Express Payments',
    'cpf_socio':       '433.476.928-45',
    'nascimento':      '29/04/1995',
    'nome_socio':      'LUCAS RAMOS DE MORAES',
}

FV_USUARIO_CLI = 'expresspayments_02'
FV_SENHA_CLI   = 'nm4NmYTKFFg'


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Cadastro Forca de Vendas PagBank')
    parser.add_argument('--dados', type=str, help='JSON com os dados do estabelecimento')
    parser.add_argument('--fv-usuario', type=str, default=FV_USUARIO_CLI)
    parser.add_argument('--fv-senha', type=str, default=FV_SENHA_CLI)
    parser.add_argument('--headless', action='store_true', default=False)
    parser.add_argument('--screenshot-dir', type=str, default='screenshots')
    args = parser.parse_args()

    dados = json.loads(args.dados) if args.dados else DADOS_CLI

    resultado = cadastrar_fv(
        dados=dados,
        fv_usuario=args.fv_usuario,
        fv_senha=args.fv_senha,
        headless=args.headless,
        screenshot_dir=args.screenshot_dir,
    )

    print(json.dumps(resultado, ensure_ascii=False, indent=2))
    sys.exit(0 if resultado['sucesso'] else 1)
