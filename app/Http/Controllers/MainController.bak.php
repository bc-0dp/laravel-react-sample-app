<?php

namespace App\Http\Controllers;

use App\Models\BCAuth;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redirect;

use GuzzleHttp\Exception\RequestException;

use GuzzleHttp\Client;

class MainController extends BaseController
{
    protected $baseURL;

    public function __construct()
    {
        $this->baseURL = env('APP_URL');
    }

    public function getAppClientId($request)
    {
        if (env('APP_ENV') === 'local') {
            return env('BC_LOCAL_CLIENT_ID');
        } else {
            return env('BC_APP_CLIENT_ID');
        }
    }

    public function getAppSecret($request)
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
            return BCAuth::where('user_id', '===', $request->session()->get('user_id'))
                ->where('user_email', '===', $request->session()->get('user_email'))
                ->value('access_token');                
        }
    }

    public function getStoreHash(Request $request)
    {
        if (env('APP_ENV') === 'local') {
            return env('BC_LOCAL_STORE_HASH');
        } else {
            return BCAuth::where('user_id', '===', $request->session()->get('user_id'))
                ->where('user_email', '===', $request->session()->get('user_email'))
                ->value('store_hash');                
        }
    }

    public function install(Request $request): RedirectResponse
    {
        // Make sure all required query params have been passed
        // if (!$request->has('code') || !$request->has('scope') || !$request->has('context')) {
        //     return redirect()->action([MainController::class, 'error'], ['error_message' => 'Not enough information was passed to install this app.']);
        // }

        $client = new Client();

        $result = $client->request('POST', 'https://login.bigcommerce.com/oauth2/token', [
            'json' => [
                'client_id'      => $this->getAppClientId($request),
                'client_secret'  => $this->getAppSecret($request),
                'redirect_uri'   => $this->baseURL . '/auth/install',
                'grant_type'     => 'authorization_code',
                'code'           => $request->input('code'),
                'scope'          => $request->input('scope'),
                'context'        => $request->input('context'),
            ]
        ]);

        if ($result->getStatusCode() !== 200) {
            return redirect()->action([MainController::class, 'error'], ['error_message' => $result->getBody()]);
        }
        
        $data = json_decode($result->getBody(), true);

        BCAuth::where('user_id', $data['user']['id'])
            ->where('user_email', $data['user']['email'])
            ->update([
                'scope'        => $data['scope'],
                'account_uuid' => $data['account_uuid'],
                'access_token' => $data['access_token']
            ]);                

        // If the merchant installed the app via an external link, redirect back to the 
        // BC installation success page for this app
        if ($request->has('external_install')) {
            return Redirect::to('https://login.bigcommerce.com/app/' . $this->getAppClientId($request) . '/install/succeeded');
        }

        return Redirect::to('/');
    }

    public function load(Request $request, Response $response): RedirectResponse
    {
        if (empty($request->input('signed_payload'))) {
            return $response->withStatus(401);
        }

        $verifiedSignedRequestData = $this->verifySignedRequest($request->input('signed_payload'), $request);

        $user = BCAuth::where('email', '===', $verifiedSignedRequestData['user']['email'])
            ->where('store_hash', '===', $verifiedSignedRequestData['context']);
            
var_dump($user); die();

        if (empty($user))
        {
            BCAuth::create([
                'user_id' =>  $verifiedSignedRequestData['user']['id'],
                'user_email' => $verifiedSignedRequestData['user']['email'],
                'store_hash' => $verifiedSignedRequestData['store_hash'],
                'locale' => $verifiedSignedRequestData['locale'],
                'timestamp' => $verifiedSignedRequestData['timestamp'],
            ]);                 
        }

        $request->session()->put('user_id', $verifiedSignedRequestData['user']['id']);
        $request->session()->put('user_email', $verifiedSignedRequestData['user']['email']);
        $request->session()->regenerate();

        return Redirect::to('/');
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
        dd($this->getStoreHash($request));

        $requestConfig = [
            'headers' => [
                'X-Auth-Client' => $this->getAppClientId($request),
                'X-Auth-Token'  => $this->getAccessToken($request),
                'Content-Type'  => 'application/json',
            ]
        ];

        if ($request->method() === 'PUT') {
            $requestConfig['body'] = $request->getContent();
        }

       

        $client = new Client();
        $result = $client->request($request->method(), 'https://api.bigcommerce.com/' . $this->getStoreHash($request) .'/'. $endpoint, $requestConfig);
        
        return $result;
    }

    public function proxyBigCommerceAPIRequest(Request $request, $endpoint)
    {
        if (strrpos($endpoint, 'v2') !== false) {
            // For v2 endpoints, add a .json to the end of each endpoint, to normalize against the v3 API standards
            $endpoint .= '.json';
        }

        $result = $this->makeBigCommerceAPIRequest($request, $endpoint);

        return response($result->getBody(), $result->getStatusCode())->header('Content-Type', 'application/json');
    }
}
