<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MercadoLibreController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $data = $request->all();
        Log::debug(["Data notification" => $data]);

        if($data["topic"] == "orders_v2"){

            $user_id = $data["user_id"];
            $resource = $data["resource"];
            $order = Order::where("resource", $resource)->first();

            if(!$order){

                $user = User::where("user_id", $user_id)->first();
                if($user){
                    try {
                        $url = "https://api.mercadolibre.com" . $resource; // /orders/2000006733067046";
                        
                        $response = Http::withHeaders([
                            'Authorization' => 'Bearer ' . $user->access_token,
                        ])->get($url);
                
                        $data = $response->json();
                        Log::debug(["status"=>$response->status()]);
                        if($response->status() == 401){
                            if($data["message"] == "invalid_token"){
                                $new_token = $this->refreshToken($user);
                                $response = Http::withHeaders([
                                    'Authorization' => 'Bearer ' . $new_token,
                                ])->get($url);   

                                $data = $response->json();
                            }
                        }

                        $new_order = $this->new_order($user_id, $resource, $data);
                    
                    } catch (\Exception $e) {
                        Log::debug(["message" => "Error al registrar venta", "error" => $e->getMessage(), $e->getLine()]);
                        return response()->json(["message" => "Error al registrar venta", "error" => $e->getMessage(), "line" => $e->getLine()], 500);
                    }
                }

            }
            // else{
                // return response()->json(["message" => "Orden ya procesada y guardada."], 500);
            // }
        }
        
        return response()->json(["message" => "Venta guardada exitosamente.", "order" => $new_order]);
    }

    public function refreshToken($user)
    {
        // Variables de configuración
        $client_id = "5379601931617009";
        $client_secret = "HbITNprHGpHFPj7pW5HGrsWjH9X3koKm";

        // Endpoint de la solicitud POST
        $url = "https://api.mercadolibre.com/oauth/token";

        try {
            // Realizar la solicitud POST
            $response = Http::post($url, [
                'grant_type' => 'refresh_token',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $user->refresh_token
            ]);

            // Obtener la respuesta en formato JSON
            $data = $response->json();

            $user->access_token = $data["access_token"];
            $user->refresh_token = $data["refresh_token"];
            $user->save();

            return $data["access_token"];
        } catch (\Exception $e) {
            return response()->json(["error" => $e->getMessage()], 500);
        }
    }

    public function new_order($user_id, $resource, $data)
    {
        $new_order = new Order();
        $new_order->user_id = $user_id;
        $new_order->resource = $resource;
        $new_order->data = $data;
        $new_order->save();

        return $new_order; 
    }
}
