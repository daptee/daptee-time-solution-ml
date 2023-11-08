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
        $new_order = null;

        if($data["topic"] == "orders_v2"){

            $user_id = $data["user_id"];
            $resource = $data["resource"];
            $order = Order::where("resource", $resource)->first();
            $user = User::where("user_id", $user_id)->first();

            if(!$order){

                if($user){
                    try {
                        $url = "https://api.mercadolibre.com" . $resource; // /orders/2000006733067046";
                        
                        $response = Http::withHeaders([ 'Authorization' => 'Bearer ' . $user->access_token ])->get($url);
                
                        $data = $response->json();

                        if($response->status() == 401){
                            $new_token = $this->refreshToken($user);
                            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $new_token])->get($url);   
                            $data = $response->json();
                        }

                        $new_order = $this->new_order($user_id, $resource, $data);
                    
                    } catch (\Exception $e) {
                        Log::debug(["message" => "Error al registrar pedido", "error" => $e->getMessage(), $e->getLine()]);
                        return response()->json(["message" => "Error al registrar pedido", "error" => $e->getMessage(), "line" => $e->getLine()], 500);
                    }
                }

                return response()->json(["message" => "Pedido guardado exitosamente.", "order" => $new_order]);

            }else{

                if($user){
                    try {
                        $url = "https://api.mercadolibre.com" . $resource; // /orders/2000006733067046";
                        
                        $response = Http::withHeaders([ 'Authorization' => 'Bearer ' . $user->access_token ])->get($url);
                
                        $data = $response->json();

                        if($response->status() == 401){
                            $new_token = $this->refreshToken($user);
                            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $new_token])->get($url);   
                            $data = $response->json();
                        }

                        $order->data = $data;
                        $order->save();
                    
                    } catch (\Exception $e) {
                        Log::debug(["message" => "Error al actualizar pedido", "error" => $e->getMessage(), $e->getLine()]);
                        return response()->json(["message" => "Error al actualizar pedido ", "error" => $e->getMessage(), "line" => $e->getLine()], 500);
                    }
                }

                return response()->json(["message" => "Pedido actulizado exitosamente.", "order" => $order]);
            }
        }
        
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
            Log::debug(["message" => "Error al actualizar token", "error" => $e->getMessage(), $e->getLine()]);
            return response()->json(["error" => $e->getMessage()], 500);
        }
    }

    public function new_order($user_id, $resource, $data)
    {
        $new_order = null;
        try {
            $new_order = new Order();
            $new_order->user_id = $user_id;
            $new_order->resource = $resource;
            $new_order->data = $data;
            $new_order->save();
            
        } catch (\Exception $e) {
            Log::debug(["message" => "Error al registrar venta (funcion principal)", "error" => $e->getMessage(), $e->getLine()]);
        }
        return $new_order; 
    }

    public function update_publication_price(Request $request)
    {
        $request->validate([
            "item_id" => "required",
            "user_id" => "required|numeric",
            "price" => "required|numeric",
        ]);

        $user = User::where("user_id", $request->user_id)->first();
        
        if(!$user)
            return response()->json(["message" => "Usuario no existente ID invalido"], 500);

        try {
            // Ejecutar endpoint para actualizar valor
            $url = "https://api.mercadolibre.com/items/" . $request->item_id; // /orders/2000006733067046";
            
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $user->access_token])
                        ->put($url, ['price' => $request->price]);
    
            $data = $response->json();

            // return $response->status();
            // Si devuelve 401 ejecutar refresh token en caso contrario seguir y hacer logica 
            if($response->status() == 401){
                $new_token = $this->refreshToken($user);
                $response = Http::withHeaders(['Authorization' => 'Bearer ' . $new_token])
                            ->put($url, ['price' => $request->price]); 

                $data = $response->json();
            }

            // No guardamos en ningun lado ese cambio de precio o algo por el estilo? CHEQUEAR CON SEBA
            // $new_order = $this->new_order($user_id, $resource, $data);
        
        } catch (\Exception $e) {
            Log::debug(["message" => "Error al actualizar precio de publicación", "error" => $e->getMessage(), $e->getLine()]);
            return response()->json(["message" => "Error al actualizar precio de publicación", "error" => $e->getMessage(), "line" => $e->getLine()], 500);
        }

        return response()->json(["message" => "Precio de publicación actualizado exitosamente.", "data" => $data]);
    }

    public function update_publication_status(Request $request)
    {
        $request->validate([
            "item_id" => "required",
            "user_id" => "required|numeric",
            "status" => "required",
        ]);

        $user = User::where("user_id", $request->user_id)->first();
        
        if(!$user)
            return response()->json(["message" => "Usuario no existente ID invalido"], 500);

        try {
            // Ejecutar endpoint para actualizar valor
            $url = "https://api.mercadolibre.com/items/" . $request->item_id; // /orders/2000006733067046";
            
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $user->access_token])
                        ->put($url, ['status' => $request->status]);
    
            $data = $response->json();

            // Si devuelve 401 ejecutar refresh token en caso contrario seguir y hacer logica 
            if($response->status() == 401){
                $new_token = $this->refreshToken($user);
                $response = Http::withHeaders(['Authorization' => 'Bearer ' . $new_token])
                            ->put($url, ['status' => $request->status]); 

                $data = $response->json();
            }

            // No guardamos en ningun lado ese cambio de estado o algo por el estilo? CHEQUEAR CON SEBA
            // $new_order = $this->new_order($user_id, $resource, $data);
        
        } catch (\Exception $e) {
            Log::debug(["message" => "Error al actualizar estado de publicación", "error" => $e->getMessage(), $e->getLine()]);
            return response()->json(["message" => "Error al actualizar estado de publicación", "error" => $e->getMessage(), "line" => $e->getLine()], 500);
        }

        return response()->json(["message" => "Estado de publicación actualizado exitosamente.", "data" => $data]);
    }

    public function update_publication_stock(Request $request)
    {
        $request->validate([
            "item_id" => "required",
            "user_id" => "required|numeric",
            "stock" => "required|numeric",
        ]);

        $user = User::where("user_id", $request->user_id)->first();
        
        if(!$user)
            return response()->json(["message" => "Usuario no existente ID invalido"], 500);

        try {
            // Ejecutar endpoint para actualizar valor
            $url = "https://api.mercadolibre.com/items/" . $request->item_id; // /orders/2000006733067046";
            
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $user->access_token])
                        ->put($url, ['available_quantity' => $request->stock]);
    
            $data = $response->json();

            // Si devuelve 401 ejecutar refresh token en caso contrario seguir y hacer logica 
            if($response->status() == 401){
                $new_token = $this->refreshToken($user);
                $response = Http::withHeaders(['Authorization' => 'Bearer ' . $new_token])
                            ->put($url, ['available_quantity' => $request->stock]); 

                $data = $response->json();
            }

            // No guardamos en ningun lado este cambio de stock o algo por el estilo? CHEQUEAR CON SEBA
            // $new_order = $this->new_order($user_id, $resource, $data);
        
        } catch (\Exception $e) {
            Log::debug(["message" => "Error al actualizar stock de publicación", "error" => $e->getMessage(), $e->getLine()]);
            return response()->json(["message" => "Error al actualizar stock de publicación", "error" => $e->getMessage(), "line" => $e->getLine()], 500);
        }

        return response()->json(["message" => "Stock de publicación actualizado exitosamente.", "data" => $data]);
    }

    public function test_api()
    {
        dd("Test api correcto");
    }
}
