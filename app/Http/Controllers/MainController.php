<?php

namespace App\Http\Controllers;

use App\Models\BCAuth;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Contracts\Auth\Authenticatable;


use GuzzleHttp\Client;
use Illuminate\Auth\Access\Response;

class MainController extends BaseController 
{
    protected $baseURL;

    public function __construct()
    {
        $this->baseURL = env('APP_URL');
    }

    public function getAppClientId()
    {
        if (env('APP_ENV') === 'local') {
            return env('BC_LOCAL_CLIENT_ID');
        } else {
            return env('BC_APP_CLIENT_ID');
        }
    }

    public function getAppSecret(Request $request)
    {
        if (env('APP_ENV') === 'local') {
            return env('BC_LOCAL_SECRET');
        } else {
            return env('BC_APP_SECRET');
        }
    }

    public function getAccessToken(Request $request)
    {
        if (env('APP_ENV') === 'local') {
            return env('BC_LOCAL_ACCESS_TOKEN');
        } else {
            // Log::debug(session('access_token'));
            return session('access_token');
        }
    }

    public function getStoreHash(Request $request)
    {
        if (env('APP_ENV') === 'local') {
            return env('BC_LOCAL_STORE_HASH');
        } else {
            // Log::debug(session('store_hash'));
            return session('store_hash');
        }
    }

    public function install(Request $request): RedirectResponse
    {
        
        // Make sure all required query params have been passed
        if (!$request->has('code') || !$request->has('scope') || !$request->has('context')) {
            return redirect()->action([MainController::class, 'error'], ['error_message' => 'Not enough information was passed to install this app.']);
        }

        try {
            $client = new Client();
            $result = $client->request('POST', 'https://login.bigcommerce.com/oauth2/token', [
                'json' => [
                    'client_id' => $this->getAppClientId(),
                    'client_secret' => $this->getAppSecret($request),
                    'redirect_uri' => $this->baseURL . '/auth/install',
                    'grant_type' => 'authorization_code',
                    'code' => $request->input('code'),
                    'scope' => $request->input('scope'),
                    'context' => $request->input('context'),
                ]
            ]);

            $statusCode = $result->getStatusCode();
            $data = json_decode($result->getBody(), true);

            if ($statusCode == 200) {
                BCAuth::create([
                    'user_id'      =>  $data['user']['id'],
                    'user_email'   => $data['user']['email'],
                    'store_hash'   => $data['context'],
                    'access_token' => $data['access_token'],
                    'account_uuid' => $data['account_uuid'],
                    'scope'        => $data['scope']
                ]);     

                // If the merchant installed the app via an external link, redirect back to the 
                // BC installation success page for this app
                if ($request->has('external_install')) {
                    return Redirect::to('https://login.bigcommerce.com/app/' . $this->getAppClientId() . '/install/succeeded');
                }
            }

            return Redirect::to('/');
        } catch (RequestException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $errorMessage = "An error occurred.";

            if ($e->hasResponse()) {
                if ($statusCode != 500) {
                    $errorMessage = $e->getResponse();
                }
            }

            // If the merchant installed the app via an external link, redirect back to the 
            // BC installation failure page for this app
            if ($request->has('external_install')) {
                return Redirect::to('https://login.bigcommerce.com/app/' . $this->getAppClientId() . '/install/failed');
            } else {
                return redirect()->action([MainController::class, 'error'], ['error_message' => $errorMessage]);
            }
        }
    }

    public function load(Request $request): RedirectResponse
    {
        $signedPayload = $request->input('signed_payload');

        if (empty($signedPayload)) {
            return redirect()->action([MainController::class, 'error'], ['error_message' => 'The signed request from BigCommerce was empty.']);
        }
        
        $verifiedSignedRequestData = $this->verifySignedRequest($signedPayload, $request);

        $user = BCAuth::where('user_email', $verifiedSignedRequestData['user']['email'])
            ->where('store_hash', $verifiedSignedRequestData['context'])->first();                

        if($user) {
            BCAuth::where('user_email', $verifiedSignedRequestData['user']['email'])
            ->where('store_hash', $verifiedSignedRequestData['context'])
            ->update([
                'locale'        => $verifiedSignedRequestData['user']['locale'],
                'owner_id'      => $verifiedSignedRequestData['owner']['id'],
                'owner_email'   => $verifiedSignedRequestData['owner']['email'],
                'timestamp'     => $verifiedSignedRequestData['timestamp']
            ]);

            session([
                'store_hash'   => $user['store_hash'],
                'access_token' => $user['access_token']
            ]);

            Log::debug(session()->getId());
            Log::debug($request->session()->all());

            return Redirect::to('/');
        }

        return redirect()->action([MainController::class, 'error'], ['error_message' => 'The signed request from BigCommerce could not be validated.']);
    }

    public function error(Request $request)
    {
        $errorMessage = "Internal Application Error";

        if ($request->session()->has('error_message')) {
            $errorMessage = $request->session()->get('error_message');
        }

        echo '<h4>An issue has occurred:</h4> <p>' . $errorMessage . '</p> <a href="' . $this->baseURL . '">Go back to home</a>';
    }

    private function verifySignedRequest($signedRequest, $appRequest)
    {
        list($encodedData, $encodedSignature) = explode('.', $signedRequest, 2);

        // decode the data
        $signature = base64_decode($encodedSignature);
        $jsonStr = base64_decode($encodedData);
        $data = json_decode($jsonStr, true);

        // confirm the signature
        $expectedSignature = hash_hmac('sha256', $jsonStr, $this->getAppSecret($appRequest), $raw = false);
        if (!hash_equals($expectedSignature, $signature)) {
            error_log('Bad signed request from BigCommerce!');
            return null;
        }
        return $data;
    }

    public function makeBigCommerceAPIRequest(Request $request, $endpoint)
    {
        $requestConfig = [
            'headers' => [
                'X-Auth-Client' => $this->getAppClientId(),
                'X-Auth-Token'  => $this->getAccessToken($request),
                'Content-Type'  => 'application/json',
            ]
        ];

        if ($request->method() === 'PUT') {
            $requestConfig['body'] = $request->getContent();
        }

        $client = new Client();
        $result = $client->request($request->method(), 'https://api.bigcommerce.com/' . $this->getStoreHash($request) . '/' . $endpoint, $requestConfig);

        return $result;
    }

    public function proxyBigCommerceAPIRequest(Request $request, $endpoint)
    {
        Log::debug($request->session()->all());
       

        if (strrpos($endpoint, 'v2') !== false) {
            // For v2 endpoints, add a .json to the end of each endpoint, to normalize against the v3 API standards
            $endpoint .= '.json';
        }

        $result = $this->makeBigCommerceAPIRequest($request, $endpoint);

        return response($result->getBody(), $result->getStatusCode())->header('Content-Type', 'application/json');
    }
}
