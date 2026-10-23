<?php

namespace App\Http\Controllers\Kyc;

use App\Http\Controllers\Controller;
use App\Models\Estabelecimento;
use App\Services\KycDocumentoSyncService;
use App\Services\KycInicializacaoService;
use App\Support\KycTipoDocumentoMapper;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;

class KycController extends Controller
{
    public function show(
        Estabelecimento $estabelecimento,
        KycInicializacaoService $kycInicializacao,
        KycDocumentoSyncService $kycSync,
    ) {
        $estabelecimento->load(['documentos', 'kycAnalise.documentos']);

        $kyc = $kycInicializacao->iniciar($estabelecimento);
        $kycSync->sincronizarTodosDoEstabelecimento($estabelecimento);

        $kyc->load(['documentos', 'historico']);

        $tiposEstabelecimento = KycTipoDocumentoMapper::tiposEstabelecimento($estabelecimento->pessoa_tipo);
        $documentosPorTipo = $estabelecimento->documentos->keyBy('tipo_documento');
        $kycPorTipo = $kyc->documentos->keyBy('tipo');

        $itens = collect($tiposEstabelecimento)->map(function (string $label) use ($documentosPorTipo, $kycPorTipo) {
            $tipoKyc = KycTipoDocumentoMapper::tipoKyc($label);
            $estabDoc = $documentosPorTipo->get($label);
            $kycDoc = $tipoKyc ? $kycPorTipo->get($tipoKyc) : null;

            return [
                'label' => $label,
                'tipo_kyc' => $tipoKyc,
                'estabelecimento_documento' => $estabDoc,
                'kyc_documento' => $kycDoc,
            ];
        });

        return view('kyc.show', [
            'estabelecimento' => $estabelecimento,
            'kyc' => $kyc,
            'itens' => $itens,
            'kycAtivo' => PlatformSettings::kycAtivo(),
            'diditConfigurado' => PlatformSettings::diditConfigurado(),
        ]);
    }

    public function enviarDocumento(Request $request, Estabelecimento $estabelecimento)
    {
        return redirect()
            ->route('estabelecimentos.show', $estabelecimento)
            ->withFragment('documentos')
            ->with('status', 'Envie os documentos na aba Documentos do estabelecimento — a análise KYC é automática.');
    }

    public function removerDocumento()
    {
        return redirect()
            ->back()
            ->with('status', 'Remova o documento na aba Documentos do estabelecimento.');
    }
}
