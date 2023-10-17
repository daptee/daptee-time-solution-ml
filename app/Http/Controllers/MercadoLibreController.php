<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoLibreController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $data = $request->all();

        Log::debug($data);
        
        return response()->json(['message' => 'Notificación recibida correctamente.']);
    }
}
