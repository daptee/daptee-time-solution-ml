<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class MercadoLibreController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $data = $request->all();
        $new_order = null;
        if($data["topic"] == "orders_v2"){

            $user_id = $data["user_id"];
            $resource = $data["resource"];
            $order = Order::where("resource", $resource)->first();
            $user = User::where("user_id", $user_id)->first();

            if($user){
                try {
                    $url = "https://api.mercadolibre.com" . $resource; // /orders/2000006733067046";
                    
                    $access_token = $user->access_token;
                    $response = Http::withHeaders([ 'Authorization' => 'Bearer ' . $access_token ])->get($url);
            
                    $response_json = $response->json();

                    if($response->status() == 401){
                        $access_token = $this->refreshToken($user);
                        $response = Http::withHeaders(['Authorization' => 'Bearer ' . $access_token])->get($url);   
                        $response_json = $response->json();
                    }

                    if($response_json['status'] == "cancelled"){
                        $this->delete_order($user_id, $resource);
                    }

                    $url_bi = "$url/billing_info";
                    $response_billing_info = Http::withHeaders(['Authorization' => 'Bearer ' . $access_token])->get($url_bi);   
                    
                    $billing_info = $response_billing_info->json();
                    if(!$order){
                        // if($response_json['status'] != "cancelled"){
                            $new_order = $this->new_order($user_id, $resource, $response_json, $billing_info);
                        // }
                    }else{
                        $order->data = $response_json;
                        $order->save();

                        $order_detail = OrderDetail::where('order_id', $order->id)->first();
                        $order_detail->publication_id = $response_json['order_items'][0]['item']['id'];
                        $order_detail->title = $response_json['order_items'][0]['item']['title'];
                        $order_detail->category_id = $response_json['order_items'][0]['item']['category_id'];
                        $order_detail->quantity = $response_json['order_items'][0]['quantity'];
                        $order_detail->unit_price = $response_json['order_items'][0]['unit_price'];
                        $order_detail->save();
                    }

                    // $this->clean_records_orders($resource);

                } catch (\Exception $e) {
                    Log::debug(["message" => "Error al registrar pedido", "error" => $e->getMessage(), $e->getLine()]);
                    return response()->json(["message" => "Error al registrar pedido", "error" => $e->getMessage(), "line" => $e->getLine()], 500);
                }
            }
        }
        return response()->json(["message" => "Pedido guardado exitosamente.", "order" => $new_order]);
        
    }

    public function delete_order($user_id, $resource)
    {
        $orders = Order::where("user_id", $user_id)->where("resource", $resource);
        foreach ($orders as $order) {
            $order_details = OrderDetail::where('order_id', $order->id)->get();
            foreach($order_details as $order_detail){
                $order_detail->delete();
            }
            $order->delete();
        }
    }

    public function refreshToken($user)
    {
        // Variables de configuración
        // $client_id = "5379601931617009";
        // $client_secret = "HbITNprHGpHFPj7pW5HGrsWjH9X3koKm";
        
        $client_id = $user->client_id;
        $client_secret = $user->client_secret;

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

    public function new_order($user_id, $resource, $data, $billing_info)
    {
        $new_order = null;
        try {
            DB::beginTransaction();

            $new_order = new Order();
            $new_order->user_id = $user_id;
            $new_order->resource = $resource;
            $new_order->data = $data;
            $new_order->order_id = $data['id'];
            $new_order->order_date = $data['date_created'];
            $new_order->payment_type = $data['payments'][0]['payment_type'];
            $new_order->status = $data['status'];
            $new_order->name = $data['buyer']['first_name'] . ' ' . $data['buyer']['last_name'];
            $new_order->document_type = $billing_info['billing_info']['doc_type'] ?? null;
            $new_order->document = $billing_info['billing_info']['doc_number'] ?? null;
            $new_order->billing_info = $billing_info ?? null;
            $new_order->save();

            $new_order_detail = new OrderDetail();
            $new_order_detail->order_id = $new_order->id;
            $new_order_detail->publication_id = $data['order_items'][0]['item']['id'];
            $new_order_detail->title = $data['order_items'][0]['item']['title'];
            $new_order_detail->category_id = $data['order_items'][0]['item']['category_id'];
            $new_order_detail->quantity = $data['order_items'][0]['quantity'];
            $new_order_detail->unit_price = $data['order_items'][0]['unit_price'];
            $new_order_detail->save();

            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::debug(["message" => "Error al registrar venta (funcion principal)", "error" => $e->getMessage(), $e->getLine()]);
            return ['error'=>"Error al registrar venta (funcion principal)", 'status'=>500];
            // Chequear con Seba mensaje
        }
        return ['order' => $new_order, 'status'=> 200];
    }

    public function clean_records_orders($resource)
    {
        $orders = Order::where("resource", $resource)->orderBy('id', 'DESC')->get();

        if ($orders->count() > 1) {
            $firstOrder = $orders->first();
            
            for ($i = 1; $i < $orders->count(); $i++) {
                if ($this->areOrdersEqual($firstOrder, $orders[$i])) {
                    // Elimina el registro si es igual al primero
                    $OrderDetail = OrderDetail::where('order_id', $orders[$i]->id)->first();
                    $orders[$i]->delete();
                    $OrderDetail->delete();
                }
            }
        }

        return $orders;
    }

    private function areOrdersEqual($order1, $order2)
    {
        // Compara los atributos para determinar si los registros son iguales
        return $order1->user_id == $order2->user_id && $order1->data == $order2->data;
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
            return response()->json(["message" => "Usuario no existente ID invalido"], 400);

        try {
            // Ejecutar endpoint para actualizar valor
            $url = "https://api.mercadolibre.com/items/" . $request->item_id; // /orders/2000006733067046";
            
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $user->access_token])
                        ->put($url, ['price' => $request->price]);
    
            if($response->status() == 404)
                return response()->json(["message" => "item_id invalido"], 400);
            
            $data = $response->json();

            // return $response->status();
            // Si devuelve 401 ejecutar refresh token en caso contrario seguir y hacer logica 
            if($response->status() == 401){
                $new_token = $this->refreshToken($user);
                $response = Http::withHeaders(['Authorization' => 'Bearer ' . $new_token])
                            ->put($url, ['price' => $request->price]); 

                $data = $response->json();
            }

        
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
            "status" => ["required", Rule::in(['active', 'paused', 'closed'])],
        ]);
        //Agregar estado 'closed'

        $user = User::where("user_id", $request->user_id)->first();
        
        if(!$user)
            return response()->json(["message" => "Usuario no existente ID invalido"], 400);

        try {
            // Ejecutar endpoint para actualizar valor
            $url = "https://api.mercadolibre.com/items/" . $request->item_id; // /orders/2000006733067046";
            
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $user->access_token])
                        ->put($url, ['status' => $request->status]);
    
            if($response->status() == 404)
                return response()->json(["message" => "item_id invalido"], 400);

            $data = $response->json();

            // Si devuelve 401 ejecutar refresh token en caso contrario seguir y hacer logica 
            if($response->status() == 401){
                $new_token = $this->refreshToken($user);
                $response = Http::withHeaders(['Authorization' => 'Bearer ' . $new_token])
                            ->put($url, ['status' => $request->status]); 

                $data = $response->json();
            }

            // No guardamos en ningun lado ese cambio de estado o algo por el estilo? CHEQUEAR CON SEBA
        
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
            "stock" => "required|integer|min:0",
        ]);

        $user = User::where("user_id", $request->user_id)->first();
        
        if(!$user)
            return response()->json(["message" => "Usuario no existente ID invalido"], 400);

        try {
            // Ejecutar endpoint para actualizar valor
            $url = "https://api.mercadolibre.com/items/" . $request->item_id; // /orders/2000006733067046";
            
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $user->access_token])
                        ->put($url, ['available_quantity' => $request->stock]);
    
            if($response->status() == 404)
                return response()->json(["message" => "item_id invalido"], 400);
            
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

    public function upload_publication_invoice(Request $request)
    {
        $request->validate([
            "order_id" => "required",
            "user_id" => "required|numeric",
            "fiscal_document" => "required|mimes:pdf,xml",
        ], [
            "fiscal_document.mimes" => "fiscal_document debe ser un archivo PDF o XML.",
        ]);

        $file = $request->file('fiscal_document');

        $user = User::where("user_id", $request->user_id)->first();
        
        if(!$user)
            return response()->json(["message" => "Usuario no existente ID invalido"], 400);

        try {
            $url = "https://api.mercadolibre.com/packs/" . $request->order_id . "/fiscal_documents";
            
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $user->access_token])
                ->attach('fiscal_document', file_get_contents($file), 'fiscal_document.pdf')->post($url);

            if($response->status() == 404)
                return response()->json(["message" => "order_id invalido"], 400);
            
            $data = $response->json();

            // Si devuelve 401 ejecutar refresh token en caso contrario seguir y hacer logica 
            if($response->status() == 401){
                $new_token = $this->refreshToken($user);
                $response = Http::withHeaders(['Authorization' => 'Bearer ' . $new_token])
                        ->attach('fiscal_document', file_get_contents($file), 'fiscal_document.pdf')->post($url); 

                $data = $response->json();
            }

        } catch (\Exception $e) {
            Log::debug(["message" => "Error al cargar factura en publicación", "error" => $e->getMessage(), $e->getLine()]);
            return response()->json(["message" => "Error al cargar factura en publicación", "error" => $e->getMessage(), "line" => $e->getLine()], 500);
        }

        return response()->json(["message" => "Carga de factura en publicación exitosamente.", "data" => $data]);
    }

    public function test_api()
    {
        dd("Test api correcto");
    }

    public function test_get_users()
    {
        $users = User::all();

        return response()->json(["users" => $users]); 
    }
}
