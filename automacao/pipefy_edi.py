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
from selenium.webdriver.common.action_chains import ActionChains
from selenium.webdriver.support.ui import WebDriverWait, Select
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.options import Options
from selenium.webdriver.chrome.service import Service
from selenium.common.exceptions import TimeoutException, NoSuchElementException, StaleElementReferenceException
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
            time.sleep(4)
            self._salvar_screenshot('portal_inicial')

            self._etapa('Selecionando Novas Ativações - EDI...')
            self._clicar_novas_ativacoes()
            self._apos_clique_novas_ativacoes()
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
                self._salvar_html_debug('erro_fatal')
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
        opcoes.add_argument('--lang=pt-BR')
        if self.headless:
            opcoes.add_argument('--headless=new')
        opcoes.add_argument('--window-size=1440,1100')
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

    def _salvar_html_debug(self, nome: str) -> None:
        try:
            caminho = os.path.join(self.screenshot_dir, f'pipefy_{nome}_{int(time.time())}.html')
            with open(caminho, 'w', encoding='utf-8') as f:
                f.write(self.driver.page_source or '')
            log.info(f'HTML debug: {caminho}')
        except Exception as e:
            log.warning(f'Falha ao salvar HTML debug: {e}')

    def _clicar_js(self, elemento) -> None:
        self.driver.execute_script('arguments[0].scrollIntoView({block: "center"});', elemento)
        time.sleep(0.25)
        try:
            elemento.click()
        except Exception:
            self.driver.execute_script('arguments[0].click();', elemento)

    def _texto_pagina(self) -> str:
        try:
            return (self.driver.find_element(By.TAG_NAME, 'body').text or '')
        except Exception:
            return ''

    def _clicar_novas_ativacoes(self) -> None:
        seletores = [
            '//*[contains(normalize-space(.),"Novas Ativações - EDI")]',
            '//*[contains(normalize-space(.),"Novas Ativações")]',
            '//button[contains(.,"Novas Ativações")]',
            '//a[contains(.,"Novas Ativações")]',
            '//div[contains(.,"Habilitação de fluxo EDI")]',
        ]
        for seletor in seletores:
            try:
                elementos = self.driver.find_elements(By.XPATH, seletor)
                # Preferir o menor nó com o texto (mais específico)
                candidatos = []
                for el in elementos:
                    texto = (el.text or '').strip()
                    if 'Novas Ativações' in texto or 'Habilitação de fluxo EDI' in texto:
                        candidatos.append((len(texto), el))
                candidatos.sort(key=lambda x: x[0])
                for _, el in candidatos:
                    clicavel = el
                    try:
                        clicavel = el.find_element(
                            By.XPATH,
                            './ancestor-or-self::*[self::button or self::a or @role="button" or contains(@class,"card")][1]',
                        )
                    except NoSuchElementException:
                        pass
                    self._clicar_js(clicavel)
                    log.info('Clicou em Novas Ativações - EDI')
                    return
            except Exception:
                continue

        if self._parece_formulario():
            log.info('Formulário já visível — pulando clique em Novas Ativações')
            return

        self._salvar_screenshot('novas_ativacoes_nao_encontrado')
        raise Exception('Opção "Novas Ativações - EDI" não encontrada no portal Pipefy.')

    def _apos_clique_novas_ativacoes(self) -> None:
        time.sleep(2)
        # Nova aba?
        if len(self.driver.window_handles) > 1:
            self.driver.switch_to.window(self.driver.window_handles[-1])
            log.info('Trocou para nova aba do formulário')
            time.sleep(1)

        self._entrar_iframe_formulario()

        # Aguarda sinais do formulário
        for _ in range(20):
            if self._parece_formulario():
                log.info('Formulário detectado')
                return
            # Às vezes abre modal/overlay com delay
            self._entrar_iframe_formulario()
            time.sleep(0.5)

        log.warning('Formulário não detectado claramente — seguindo mesmo assim')
        log.info('Texto visível (amostra): %s', self._texto_pagina()[:500].replace('\n', ' | '))

    def _entrar_iframe_formulario(self) -> None:
        self.driver.switch_to.default_content()
        iframes = self.driver.find_elements(By.TAG_NAME, 'iframe')
        log.info(f'Iframes na página: {len(iframes)}')
        for idx, frame in enumerate(iframes):
            try:
                self.driver.switch_to.default_content()
                self.driver.switch_to.frame(frame)
                texto = self._texto_pagina()
                if any(t in texto for t in (
                    'tipo da sua solicitação',
                    'Token',
                    'ID Origem',
                    'Novas Ativações',
                    'Create new card',
                    'Quem é você',
                )):
                    log.info(f'Entrou no iframe #{idx} do formulário')
                    return
            except Exception:
                continue
        self.driver.switch_to.default_content()

    def _parece_formulario(self) -> bool:
        texto = self._texto_pagina().lower()
        sinais = [
            'tipo da sua solicitação',
            'id origem',
            'create new card',
            'edi - novas ativações',
            'quem é você',
            'replicação do token',
        ]
        return any(s in texto for s in sinais) or self._existe_campo_token()

    def _existe_campo_token(self) -> bool:
        return bool(self.driver.find_elements(
            By.XPATH,
            '//*[contains(normalize-space(.),"Token") and (self::label or self::span or self::div)]'
            '/following::input[1] | //input[contains(@placeholder,"Token") or contains(@name,"token")]',
        ))

    def _preencher_formulario(self) -> None:
        self._responder_quem_e_voce()
        self._preencher_contatos_iniciais()

        # E-mail / domínio
        self._preencher_por_label(
            [
                'mail de mesmo domínio',
                'mesmo domínio',
                'e-mail',
                'email',
                'E-mail',
            ],
            self.email,
            opcional=True,
        )

        self._selecionar_tipo_solicitacao()

        # Token e ID Origem só aparecem depois do tipo "Replicação"
        time.sleep(1)
        self._salvar_screenshot('apos_tipo')

        self._preencher_por_label(['* Token', 'Token'], self.token, opcional=False)
        self._preencher_por_label(['ID Origem', 'ID origem', 'Id Origem'], self.id_origem, opcional=False)
        self._preencher_por_label(
            ['Descrição', 'Descricao', 'descrição'],
            self.descricao,
            opcional=True,
            multilinha=True,
        )

        if self.razao_social:
            self._preencher_por_label(
                ['Razão social', 'Razao social', 'Nome da empresa', 'Empresa'],
                self.razao_social,
                opcional=True,
            )
        if self.cnpj:
            self._preencher_por_label(['CNPJ', 'CPF/CNPJ', 'Documento'], self.cnpj, opcional=True)
        if self.telefone:
            self._preencher_por_label(['Telefone', 'Celular'], self.telefone, opcional=True)

    def _responder_quem_e_voce(self) -> None:
        texto = self._texto_pagina()
        if 'Quem é você' not in texto and 'Quem é você?' not in texto:
            return

        log.info('Respondendo "Quem é você?"')
        # Preferência: Cliente (matriz própria). Fallback: Conciliador / Comercial
        opcoes = ['Cliente', 'Conciliador', 'Comercial', 'Cliente PagBank']
        for opcao in opcoes:
            if self._clicar_texto_visivel(opcao, exato=False):
                log.info(f'Selecionou quem é você: {opcao}')
                time.sleep(1)
                self._salvar_screenshot('quem_e_voce')
                return

    def _preencher_contatos_iniciais(self) -> None:
        # Campos comuns da 1ª etapa do formulário PagBank
        if self.razao_social:
            self._preencher_por_label(
                ['Nome', 'Seu nome', 'nome completo'],
                self.razao_social,
                opcional=True,
            )
        self._clicar_texto_visivel('Empresa', exato=False)
        self._clicar_texto_visivel('Pessoa Jurídica', exato=False)
        if self.email:
            self._preencher_por_label(['E-mail', 'Email', 'e-mail para contato'], self.email, opcional=True)
        if self.telefone:
            self._preencher_por_label(['Telefone', 'Celular'], self.telefone, opcional=True)

    def _tipo_ja_selecionado(self) -> bool:
        texto = self._texto_pagina()
        return 'Replicação do token' in texto or 'Replicacao do token' in texto or 'já possui um token' in texto

    def _selecionar_tipo_solicitacao(self) -> None:
        if self._tipo_ja_selecionado() and self._existe_campo_token():
            log.info('Tipo já parece selecionado e campo Token visível — seguindo')
            return

        # 1) select nativo
        if self._selecionar_select_nativo():
            return

        # 2) Abrir dropdown custom perto do label
        if not self._abrir_dropdown_tipo():
            # Pode ser que o label ainda não carregou — tenta avançar etapas
            self._clicar_texto_visivel('Continuar', exato=False)
            self._clicar_texto_visivel('Avançar', exato=False)
            time.sleep(1)
            self._abrir_dropdown_tipo()

        time.sleep(0.8)

        # 3) Escolher opção
        if self._escolher_opcao_replicacao():
            time.sleep(1)
            return

        # 4) Digitar no combobox ativo
        try:
            ativo = self.driver.switch_to.active_element
            ativo.send_keys('Replicação')
            time.sleep(0.6)
            ativo.send_keys(Keys.ENTER)
            time.sleep(0.8)
            if self._tipo_ja_selecionado() or self._existe_campo_token():
                log.info('Tipo selecionado via teclado')
                return
        except Exception:
            pass

        # 5) Última tentativa: clicar qualquer opção listbox
        for xpath in [
            '//*[@role="option"]',
            '//li[contains(@class,"option") or @role="option"]',
            '//div[contains(@class,"option")]',
        ]:
            for op in self.driver.find_elements(By.XPATH, xpath):
                try:
                    t = (op.text or '').strip()
                    if 'Replic' in t or 'token' in t.lower():
                        self._clicar_js(op)
                        log.info(f'Tipo via listbox: {t[:80]}')
                        time.sleep(1)
                        return
                except StaleElementReferenceException:
                    continue

        amostra = self._texto_pagina()[:800].replace('\n', ' | ')
        log.error(f'Texto da página ao falhar tipo: {amostra}')
        raise Exception('Não foi possível selecionar "Replicação do token API EDI".')

    def _selecionar_select_nativo(self) -> bool:
        selects = self.driver.find_elements(By.TAG_NAME, 'select')
        for sel in selects:
            try:
                if not sel.is_displayed():
                    continue
                s = Select(sel)
                for opt in s.options:
                    texto = (opt.text or '').strip()
                    if 'Replic' in texto or 'token' in texto.lower():
                        s.select_by_visible_text(opt.text)
                        log.info(f'Select nativo: {texto[:80]}')
                        return True
            except Exception:
                continue
        return False

    def _abrir_dropdown_tipo(self) -> bool:
        triggers = [
            '//*[contains(normalize-space(.),"Qual o tipo da sua solicitação")]/following::input[1]',
            '//*[contains(normalize-space(.),"Qual o tipo da sua solicitação")]/following::*[@role="combobox"][1]',
            '//*[contains(normalize-space(.),"Qual o tipo da sua solicitação")]/following::button[1]',
            '//*[contains(normalize-space(.),"Qual o tipo da sua solicitação")]/following::div[contains(@class,"select") or contains(@class,"dropdown") or @role="button"][1]',
            '//label[contains(.,"tipo da sua solicitação")]/following::*[self::input or self::button or @role="combobox"][1]',
            '//*[contains(normalize-space(.),"tipo da sua solicitação")]/ancestor::*[contains(@class,"field") or contains(@class,"Form")][1]//*[self::input or self::button or @role="combobox"]',
            '//*[@role="combobox"]',
            '//input[contains(@placeholder,"Selecione") or contains(@placeholder,"selecione")]',
            '//*[contains(@class,"react-select__control") or contains(@class,"Select__control") or contains(@class,"pp-react-select")]',
        ]
        for xpath in triggers:
            try:
                els = self.driver.find_elements(By.XPATH, xpath)
                for el in els:
                    if not el.is_displayed():
                        continue
                    # Evita clicar em combobox irrelevante se houver label de tipo na página
                    self._clicar_js(el)
                    log.info(f'Abriu dropdown tipo via: {xpath[:80]}')
                    time.sleep(0.7)
                    return True
            except Exception:
                continue

        # Clique no próprio texto do label (às vezes foca o campo)
        if self._clicar_texto_visivel('Qual o tipo da sua solicitação', exato=False):
            time.sleep(0.5)
            try:
                ActionChains(self.driver).send_keys(Keys.SPACE).perform()
            except Exception:
                pass
            return True

        log.warning('Não foi possível abrir o select de tipo de solicitação')
        return False

    def _escolher_opcao_replicacao(self) -> bool:
        trechos = [
            'Replicação do token API EDI',
            'Replicacao do token API EDI',
            'já possui um token',
            'ja possui um token',
            'Usuário que já possui',
            'Usuario que ja possui',
            self.tipo_solicitacao,
        ]
        for trecho in trechos:
            if not trecho:
                continue
            # Preferir role=option
            xpaths = [
                f'//*[@role="option" and contains(normalize-space(.),"{trecho}")]',
                f'//li[contains(normalize-space(.),"{trecho}")]',
                f'//div[contains(normalize-space(.),"{trecho}") and (contains(@class,"option") or @role="option" or contains(@class,"item"))]',
                f'//*[contains(normalize-space(.),"{trecho}")]',
            ]
            for xpath in xpaths:
                for op in self.driver.find_elements(By.XPATH, xpath):
                    try:
                        if not op.is_displayed():
                            continue
                        texto = (op.text or '').strip()
                        if not texto:
                            continue
                        # Evita clicar no próprio label da pergunta
                        if 'Qual o tipo' in texto and 'Replic' not in texto:
                            continue
                        if 'Replic' in texto or 'token' in texto.lower() or 'possui' in texto.lower():
                            self._clicar_js(op)
                            log.info(f'Tipo de solicitação: {texto[:100]}')
                            return True
                    except StaleElementReferenceException:
                        continue
        return False

    def _clicar_texto_visivel(self, texto: str, exato: bool = False) -> bool:
        if exato:
            xpath = f'//*[normalize-space(.)="{texto}"]'
        else:
            xpath = f'//*[contains(normalize-space(.),"{texto}")]'
        try:
            for el in self.driver.find_elements(By.XPATH, xpath):
                if not el.is_displayed():
                    continue
                t = (el.text or '').strip()
                if not t:
                    continue
                if exato and t != texto:
                    continue
                # Preferir nós pequenos
                if len(t) > 80 and not exato:
                    continue
                self._clicar_js(el)
                return True
        except Exception:
            return False
        return False

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
                f'//label[contains(normalize-space(.),"{label}")]/following::input[not(@type="hidden") and not(@type="checkbox") and not(@type="radio")][1]',
                f'//*[self::label or self::span or self::div][contains(normalize-space(.),"{label}")]/following::textarea[1]',
                f'//*[self::label or self::span or self::div][contains(normalize-space(.),"{label}")]/following::input[not(@type="hidden") and not(@type="checkbox") and not(@type="radio")][1]',
                f'//textarea[contains(@placeholder,"{label}")]',
                f'//input[contains(@placeholder,"{label}")]',
            ]
            if multilinha:
                xpaths = [x for x in xpaths if 'textarea' in x] + [x for x in xpaths if 'textarea' not in x]

            for xpath in xpaths:
                try:
                    for campo in self.driver.find_elements(By.XPATH, xpath):
                        if not campo.is_displayed():
                            continue
                        tag = (campo.tag_name or '').lower()
                        if multilinha and tag != 'textarea':
                            # ainda tenta, mas prefere textarea
                            pass
                        self.driver.execute_script('arguments[0].scrollIntoView({block:"center"});', campo)
                        time.sleep(0.15)
                        try:
                            campo.click()
                            campo.clear()
                        except Exception:
                            self.driver.execute_script('arguments[0].value = "";', campo)
                        campo.send_keys(valor)
                        # Dispara eventos React
                        self.driver.execute_script(
                            "arguments[0].dispatchEvent(new Event('input', {bubbles:true}));"
                            "arguments[0].dispatchEvent(new Event('change', {bubbles:true}));",
                            campo,
                        )
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
            '//button[contains(.,"Criar card")]',
            '//button[contains(.,"Criar")]',
            '//button[contains(.,"Enviar")]',
            '//button[@type="submit"]',
            '//*[contains(@class,"pp-button") and (contains(.,"Create") or contains(.,"Criar"))]',
        ]
        for xpath in botoes:
            try:
                btn = WebDriverWait(self.driver, 8).until(EC.element_to_be_clickable((By.XPATH, xpath)))
                self._clicar_js(btn)
                log.info(f'Clicou em enviar: {(btn.text or "")[:60]}')
                time.sleep(3)
                break
            except TimeoutException:
                continue
        else:
            self._salvar_screenshot('botao_enviar_nao_encontrado')
            raise Exception('Botão de envio do chamado não encontrado.')

        card_id = None
        url = self.driver.current_url or ''
        m = re.search(r'/cards?/(\d+)', url)
        if m:
            card_id = m.group(1)
        else:
            try:
                texto = self._texto_pagina()
                m2 = re.search(r'(?:card|chamado|protocolo)\s*[#:.]?\s*(\d{4,})', texto, re.I)
                if m2:
                    card_id = m2.group(1)
            except Exception:
                pass

        try:
            erros = self.driver.find_elements(
                By.XPATH,
                '//*[contains(@class,"error") or contains(@class,"invalid") or contains(@role,"alert")]',
            )
            msgs = [e.text.strip() for e in erros if e.is_displayed() and e.text.strip()]
            if msgs and not card_id:
                raise Exception('Formulário retornou erro: ' + '; '.join(msgs[:3]))
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
