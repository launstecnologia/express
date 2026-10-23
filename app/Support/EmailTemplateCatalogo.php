<?php

namespace App\Support;

class EmailTemplateCatalogo
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function padroes(): array
    {
        return [
            [
                'slug' => 'auth.reset_senha',
                'nome' => 'Redefinição de senha',
                'categoria' => 'auth',
                'assunto' => 'Redefinição de senha — {app_name}',
                'corpo' => "Olá {nome},\n\nRecebemos uma solicitação para redefinir sua senha na plataforma {app_name}.\n\nClique no botão abaixo para criar uma nova senha. O link é válido por {expira}.\n\nSe você não solicitou esta alteração, ignore este e-mail.",
                'botao_texto' => 'Redefinir senha',
                'placeholders_ajuda' => '{nome}, {link}, {app_name}, {expira}',
            ],
            [
                'slug' => 'estabelecimento.cadastro',
                'nome' => 'Cadastro de estabelecimento recebido',
                'categoria' => 'estabelecimento',
                'assunto' => 'Documentos e aceite das taxas — {app_name}',
                'corpo' => "Olá {nome},\n\nSeu cadastro na plataforma {app_name} foi recebido.\n\nEstabelecimento: {estabelecimento}\nDocumento: {documento}\n\nPara concluir, envie os documentos e assine o aceite das taxas, inclusive as condições de chargeback, neste link:\n\n{link_documentos}\n\nO link abre no celular. Nele você fotografa os documentos, confere as taxas e assina.",
                'botao_texto' => 'Enviar documentos e assinar',
                'placeholders_ajuda' => '{nome}, {estabelecimento}, {documento}, {link}, {link_documentos}, {app_name}',
            ],
            [
                'slug' => 'kyc.em_analise',
                'nome' => 'KYC em análise',
                'categoria' => 'kyc',
                'assunto' => 'Documentos em análise — {app_name}',
                'corpo' => "Olá {nome},\n\nTodos os documentos do estabelecimento {estabelecimento} foram recebidos e estão em análise pela nossa equipe.\n\nVocê será notificado assim que houver uma decisão.",
                'botao_texto' => 'Ver status do KYC',
                'placeholders_ajuda' => '{nome}, {estabelecimento}, {link}, {app_name}',
            ],
            [
                'slug' => 'kyc.revisao_manual',
                'nome' => 'KYC aguardando revisão (admin)',
                'categoria' => 'kyc',
                'assunto' => '[Admin] KYC aguardando revisão — {estabelecimento}',
                'corpo' => "Olá,\n\nO KYC do estabelecimento {estabelecimento} requer revisão manual.\n\nAcesse o painel administrativo para analisar os documentos.",
                'botao_texto' => 'Abrir KYC no admin',
                'placeholders_ajuda' => '{estabelecimento}, {link}, {app_name}',
            ],
            [
                'slug' => 'kyc.aprovado',
                'nome' => 'KYC aprovado',
                'categoria' => 'kyc',
                'assunto' => 'KYC aprovado — {app_name}',
                'corpo' => "Olá {nome},\n\nBoas notícias! O KYC do estabelecimento {estabelecimento} foi aprovado.\n\nEstamos dando continuidade ao cadastro junto ao PagBank.",
                'botao_texto' => 'Ver estabelecimento',
                'placeholders_ajuda' => '{nome}, {estabelecimento}, {link}, {app_name}, {motivo}',
            ],
            [
                'slug' => 'kyc.reprovado',
                'nome' => 'KYC reprovado',
                'categoria' => 'kyc',
                'assunto' => 'KYC reprovado — {app_name}',
                'corpo' => "Olá {nome},\n\nInfelizmente o KYC do estabelecimento {estabelecimento} foi reprovado.\n\nMotivo: {motivo}\n\nEntre em contato conosco ou envie novos documentos pelo painel.",
                'botao_texto' => 'Ver detalhes',
                'placeholders_ajuda' => '{nome}, {estabelecimento}, {motivo}, {link}, {app_name}',
            ],
            [
                'slug' => 'pagbank.cadastro_sucesso',
                'nome' => 'Conta PagBank criada',
                'categoria' => 'pagbank',
                'assunto' => 'Conta PagBank criada — {app_name}',
                'corpo' => "Olá {nome},\n\nA conta PagBank do estabelecimento {estabelecimento} foi criada com sucesso.\n\nID da conta: {account_id}",
                'botao_texto' => 'Ver estabelecimento',
                'placeholders_ajuda' => '{nome}, {estabelecimento}, {account_id}, {link}, {app_name}',
            ],
            [
                'slug' => 'pagbank.cadastro_erro',
                'nome' => 'Erro no cadastro PagBank',
                'categoria' => 'pagbank',
                'assunto' => 'Falha no cadastro PagBank — {estabelecimento}',
                'corpo' => "Olá {nome},\n\nNão foi possível concluir o cadastro PagBank do estabelecimento {estabelecimento}.\n\nErro: {motivo}\n\nNossa equipe foi notificada e entrará em contato se necessário.",
                'botao_texto' => 'Ver estabelecimento',
                'placeholders_ajuda' => '{nome}, {estabelecimento}, {motivo}, {link}, {app_name}',
            ],
            [
                'slug' => 'pagbank.cadastro_erro_admin',
                'nome' => 'Erro PagBank (admin)',
                'categoria' => 'pagbank',
                'assunto' => '[Admin] Falha PagBank — {estabelecimento}',
                'corpo' => "Cadastro PagBank falhou para {estabelecimento}.\n\nErro: {motivo}\n\nVerifique logs e credenciais em Configurações → PagBank.",
                'botao_texto' => 'Abrir estabelecimento',
                'placeholders_ajuda' => '{estabelecimento}, {motivo}, {link}, {app_name}',
            ],
            [
                'slug' => 'chamado.aberto',
                'nome' => 'Novo chamado aberto (admin)',
                'categoria' => 'chamado',
                'assunto' => '[Chamado {numero}] {titulo}',
                'corpo' => "Um novo chamado foi aberto na plataforma {app_name}.\n\nNúmero: {numero}\nTítulo: {titulo}\nCategoria: {categoria}\nPrioridade: {prioridade}\nAberto por: {nome}\n\nMensagem:\n{mensagem}",
                'botao_texto' => 'Ver chamado',
                'placeholders_ajuda' => '{numero}, {titulo}, {categoria}, {prioridade}, {nome}, {mensagem}, {link}, {app_name}',
            ],
            [
                'slug' => 'chamado.resposta',
                'nome' => 'Nova resposta no chamado',
                'categoria' => 'chamado',
                'assunto' => 'Resposta no chamado {numero} — {app_name}',
                'corpo' => "Olá {nome},\n\nHá uma nova resposta no chamado {numero} — {titulo}.\n\n{mensagem}",
                'botao_texto' => 'Ver chamado',
                'placeholders_ajuda' => '{nome}, {numero}, {titulo}, {mensagem}, {link}, {app_name}',
            ],
            [
                'slug' => 'chamado.status',
                'nome' => 'Status do chamado alterado',
                'categoria' => 'chamado',
                'assunto' => 'Chamado {numero} — status: {status}',
                'corpo' => "Olá {nome},\n\nO status do chamado {numero} foi alterado para: {status}.\n\nTítulo: {titulo}",
                'botao_texto' => 'Ver chamado',
                'placeholders_ajuda' => '{nome}, {numero}, {titulo}, {status}, {link}, {app_name}',
            ],
            [
                'slug' => 'usuario.criado',
                'nome' => 'Boas-vindas — usuário criado',
                'categoria' => 'usuario',
                'assunto' => 'Acesso criado — {app_name}',
                'corpo' => "Olá {nome},\n\nSeu acesso à plataforma {app_name} foi criado.\n\nE-mail: {email}\nPerfil: {perfil}\n\nUse o link abaixo para acessar e definir sua senha, se necessário.",
                'botao_texto' => 'Acessar plataforma',
                'placeholders_ajuda' => '{nome}, {email}, {perfil}, {link}, {app_name}',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function categorias(): array
    {
        return [
            'auth' => 'Autenticação',
            'estabelecimento' => 'Estabelecimento',
            'kyc' => 'KYC',
            'pagbank' => 'PagBank',
            'chamado' => 'Chamados',
            'usuario' => 'Usuários',
        ];
    }
}
