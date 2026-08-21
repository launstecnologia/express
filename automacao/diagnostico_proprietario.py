#!/usr/bin/env python3
"""
Diagnóstico da etapa "Dados do proprietário" no portal FV PagBank.

Uso no VPS (com credenciais FV):
  cd /var/www/plataforma_express/automacao
  python3 diagnostico_proprietario.py \\
    --fv-usuario SEU_USUARIO \\
    --fv-senha SUA_SENHA \\
    --cnpj 55.087.974/0001-46 \\
    --cpf-socio 148.602.504-86 \\
    --nascimento 21/02/2001 \\
    --nome-socio "ROBERTO DA CONCEICAO ATAIDE" \\
    --email teste@express.app.br \\
    --celular 82987186663 \\
    --razao-social "55087974 ROBERTO DA CONCEICAO ATAIDE" \\
    --nome-fantasia "ROBERTO ATAIDE"

Ou carregue de automacao/.env + argumentos mínimos:
  python3 diagnostico_proprietario.py --cnpj 55.087.974/0001-46 ...
"""
from __future__ import annotations

import argparse
import os
import sys
import time

from pathlib import Path

from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parent / '.env')

from main import AUTOMACAO_CODIGO_VERSAO, CadastradorFV


def main() -> int:
    parser = argparse.ArgumentParser(description='Diagnóstico etapa proprietário FV')
    parser.add_argument('--fv-usuario', default=os.getenv('AUTOMACAO_FV_USUARIO', ''))
    parser.add_argument('--fv-senha', default=os.getenv('AUTOMACAO_FV_SENHA', ''))
    parser.add_argument('--cnpj', required=True)
    parser.add_argument('--email', default='diag@express.app.br')
    parser.add_argument('--celular', default='82987186663')
    parser.add_argument('--razao-social', default='')
    parser.add_argument('--nome-fantasia', default='DIAGNOSTICO')
    parser.add_argument('--cpf-socio', default='148.602.504-86')
    parser.add_argument('--nascimento', default='21/02/2001')
    parser.add_argument('--nome-socio', default='ROBERTO DA CONCEICAO ATAIDE')
    parser.add_argument('--faturamento', default='De R$ 1 mil até R$ 5 mil')
    parser.add_argument('--headless', action='store_true', default=False)
    parser.add_argument('--output', default='/tmp/diag_fv')
    args = parser.parse_args()

    if not args.fv_usuario or not args.fv_senha:
        print('Erro: informe --fv-usuario e --fv-senha (ou AUTOMACAO_FV_* no .env)', file=sys.stderr)
        return 1

    os.makedirs(args.output, exist_ok=True)
    print(f'Versão automação: {AUTOMACAO_CODIGO_VERSAO}')
    print(f'Screenshots/diag em: {args.output}')

    dados = {
        'cpf_cnpj': args.cnpj,
        'email': args.email,
        'email_confirmar': args.email,
        'celular': args.celular,
        'razao_social': args.razao_social or args.nome_fantasia,
        'nome_fantasia': args.nome_fantasia,
        'cpf_socio': args.cpf_socio,
        'nascimento': args.nascimento,
        'nome_socio': args.nome_socio,
        'faturamento': args.faturamento,
    }

    bot = CadastradorFV(
        dados=dados,
        fv_usuario=args.fv_usuario,
        fv_senha=args.fv_senha,
        headless=args.headless,
        screenshot_dir=args.output,
    )

    try:
        bot.driver = bot._iniciar_browser()
        bot.wait = __import__('selenium.webdriver.support.ui', fromlist=['WebDriverWait']).WebDriverWait(
            bot.driver, 20
        )

        bot._fazer_login()
        bot._navegar_cadastrar_cliente()
        bot._preencher_dados_iniciais()
        bot._preparar_dados_pj()
        bot._preencher_dados_empresa()

        diag = bot._salvar_diagnostico_formulario('antes_preencher_proprietario')
        print(f'\n--- Campos visíveis (antes de preencher) ---')
        with open(diag, encoding='utf-8') as fh:
            print(fh.read())

        print('\n--- Tentando preencher proprietário ---')
        bot._preencher_dados_proprietario()
        print('OK: etapa proprietário preenchida com sucesso')

        bot._salvar_diagnostico_formulario('depois_preencher_proprietario')
        return 0

    except Exception as exc:
        print(f'\nFALHA: {exc}', file=sys.stderr)
        if bot.driver:
            try:
                bot._salvar_diagnostico_formulario('erro')
            except Exception:
                pass
        return 2

    finally:
        if bot.driver:
            time.sleep(2)
        bot._fechar_browser()


if __name__ == '__main__':
    raise SystemExit(main())
