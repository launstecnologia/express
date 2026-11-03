<?php

namespace Tests\Unit;

use App\Models\Estabelecimento;
use App\Support\EmailPagBank;
use RuntimeException;
use Tests\TestCase;

class EmailPagBankTest extends TestCase
{
    public function test_rejeita_o_email_do_cliente(): void
    {
        $estabelecimento = new Estabelecimento([
            'email' => 'filomenalopes0601@gmail.com',
            'webmail_email' => null,
        ]);

        $this->assertNull(EmailPagBank::daPlataforma($estabelecimento->email));

        $this->expectException(RuntimeException::class);
        EmailPagBank::obrigatorio($estabelecimento);
    }

    public function test_aceita_somente_o_email_da_plataforma(): void
    {
        $estabelecimento = new Estabelecimento([
            'email' => 'filomenalopes0601@gmail.com',
            'webmail_email' => 'Loja@express.app.br',
        ]);

        $this->assertSame('loja@express.app.br', EmailPagBank::obrigatorio($estabelecimento));
    }
}
