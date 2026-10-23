<?php

namespace App\Http\Controllers\Estabelecimento;

use App\Http\Controllers\Controller;
use App\Models\Estabelecimento;
use Illuminate\Support\Facades\Storage;

class EstabelecimentoAceiteController extends Controller
{
    public function assinatura(Estabelecimento $estabelecimento)
    {
        $aceite = $estabelecimento->aceite;

        abort_unless($aceite && Storage::disk('local')->exists($aceite->assinatura_path), 404);

        return Storage::disk('local')->response($aceite->assinatura_path, 'assinatura.png', [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
