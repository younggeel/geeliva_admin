<?php

namespace App\Http\Controllers;

use App\Services\FirestoreClient;
use paytm\paytmchecksum\PaytmChecksum;
use Illuminate\Http\Request;
use Response;
use Braintree;

class PaymentController extends Controller
{
    public function __construct(private FirestoreClient $firestore)
    {
    }

    public function getPaytmChecksum(Request $request)
    {
        $request->validate(['mid' => 'required|string', 'order_id' => 'required|string', 'key_secret' => 'required|string']);
        $input=$request->all();

        $paytmParams = array();

        /* add parameters in Array */
        $paytmParams["MID"] = $input['mid'];
        $paytmParams["ORDERID"] = $input['order_id'];
        $merchant_key=$input['key_secret'];
        /**
        * Generate checksum by parameters we have
        * Find your Merchant Key in your Paytm Dashboard at https://dashboard.paytm.com/next/apikeys 
        */
        $paytmChecksum = PaytmChecksum::generateSignature($paytmParams, $merchant_key);
        $result=array('code'=>$paytmChecksum);
        return response()->json($result);
    }

    public function validateChecksum(Request $request)
    {
        $request->validate(['mid' => 'required|string', 'order_id' => 'required|string', 'key_secret' => 'required|string', 'checksum_value' => 'required|string']);
        $input=$request->all();

        $paytmParams = array();

        /* add parameters in Array */
        $paytmParams["MID"] = $input['mid'];
        $paytmParams["ORDERID"] = $input['order_id'];
        $merchant_key=$input['key_secret'];
        /**
        * Generate checksum by parameters we have
        * Find your Merchant Key in your Paytm Dashboard at https://dashboard.paytm.com/next/apikeys 
        */
        $mid=$input['mid'];
        $orderId=$input['order_id'];
        $body= array('mid'=>$mid,'orderId'=>$orderId);
        /* checksum that we need to verify */
        $paytmChecksum = $input['checksum_value'];

        /**
        * Verify checksum
        * Find your Merchant Key in your Paytm Dashboard at https://dashboard.paytm.com/next/apikeys 
        */
        $isVerifySignature = PaytmChecksum::verifySignature($body, $merchant_key, $paytmChecksum);
        
        if($isVerifySignature) {
            $result=array('status'=>true);
        } else {
            $result=array('status'=>false);
        }
        
        return response()->json($result);
    }


    public function initiatePaytmPayment(Request $request)
    {
        $request->validate([
            'mid' => 'required|string', 'order_id' => 'required|string', 'callback_url' => 'required|string',
            'amount' => 'required|numeric', 'currency' => 'required|string', 'custId' => 'required|string',
            'key_secret' => 'required|string', 'issandbox' => 'required',
        ]);
        $inputs=$request->all();
        $paytmParams = array();

        $paytmParams["body"] = array(
            "requestType" => "Payment",
            "mid" => $inputs['mid'],
            "websiteName" => "Foodie",
            "orderId" => $inputs['order_id'],
            "callbackUrl" => $inputs['callback_url'],
            "txnAmount" => array(
            "value" => $inputs['amount'],
            "currency"=> $inputs['currency'],
            ),
            "userInfo"=> array(
            "custId"=> $inputs['custId'],
            ),
        );

        /*
        * Generate checksum by parameters we have in body
        * Find your Merchant Key in your Paytm Dashboard at https://dashboard.paytm.com/next/apikeys
        */
        $checksum = PaytmChecksum::generateSignature(json_encode($paytmParams["body"], JSON_UNESCAPED_SLASHES), $inputs['key_secret']);

        $paytmParams["head"] = array("signature"=> $checksum);

        $post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);

        /* for Staging */
        if($inputs['issandbox']){
            $url = "https://securegw-stage.paytm.in/theia/api/v1/initiateTransaction?mid=".$inputs['mid']."&orderId=".$inputs['order_id'];
        }else{
        /* for Production */
         $url = "https://securegw.paytm.in/theia/api/v1/initiateTransaction?mid=".$inputs['mid']."&orderId=".$inputs['order_id'];
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
        $response = curl_exec($ch);
        $response=json_decode($response);
        return response()->json($response);
    }

    /**
     * Paytm calls back here server-to-server after a transaction -- no
     * session/auth is possible on this route, so integrity comes from
     * verifying Paytm's own checksum against our merchant key (read
     * server-side from Firestore, never trusted from the request itself)
     * before treating the callback data as genuine.
     */
    public function paytmPaymentcallback(Request $request)
    {
        $data = $request->all();
        $checksum = $data['CHECKSUMHASH'] ?? null;
        unset($data['CHECKSUMHASH']);

        $settings = $this->firestore->get('settings', 'PaytmSettings');
        $merchantKey = $settings['PAYTM_MERCHANT_KEY'] ?? null;

        if (!$checksum || !$merchantKey || !PaytmChecksum::verifySignature($data, $merchantKey, $checksum)) {
            \Log::warning('PaymentController: Paytm callback failed checksum verification', ['order_id' => $data['ORDERID'] ?? null]);
            return response()->json(['success' => false, 'message' => 'Checksum verification failed'], 400);
        }

        return response()->json(array('success'=>true,'data'=>$data));
    }

    public function getPaypalClienttoken(Request $request){
        $request->validate(['environment' => 'required|string', 'merchant_id' => 'required|string', 'public_key' => 'required|string', 'private_key' => 'required|string']);
        $input=$request->all();
        $gateway = new Braintree\Gateway([
            'environment' => $input['environment'],
            'merchantId' => $input['merchant_id'],
            'publicKey' => $input['public_key'],
            'privateKey' => $input['private_key']
        ]);

        $clientToken = $gateway->clientToken()->generate();

        return response()->json(array('success'=>true,'data'=>$clientToken));  
    }

    public function createBraintreePayment(Request $request){
        $request->validate([
            'nonceFromTheClient' => 'required|string', 'amount' => 'required|numeric', 'deviceDataFromTheClient' => 'required',
            'environment' => 'required|string', 'merchant_id' => 'required|string', 'public_key' => 'required|string', 'private_key' => 'required|string',
        ]);
        $input=$request->all();
        $nonceFromTheClient=$input['nonceFromTheClient'];
        $amount=$input['amount'];
        $deviceDataFromTheClient=$input['deviceDataFromTheClient'];
        $gateway = new Braintree\Gateway([
            'environment' => $input['environment'],
            'merchantId' => $input['merchant_id'],
            'publicKey' => $input['public_key'],
            'privateKey' => $input['private_key']
        ]);

        
            $result = $gateway->transaction()->sale([
              'amount' => $amount,
              'paymentMethodNonce' => $nonceFromTheClient,
              'deviceData' => $deviceDataFromTheClient,
              'options' => [
                'submitForSettlement' => True
              ]
            ]);

        return response()->json(array('success'=>true,'data'=>$result));
    }

    public function createStripePaymentIntent(Request $request)
    {
        $request->validate(['stripesecret' => 'required|string', 'amount' => 'required|numeric', 'currency' => 'required|string']);
        $input=$request->all();
        $stripeSecret=$input['stripesecret'];
        $amount=$input['amount'];
        $currency=$input['currency'];
        $stripe = new \Stripe\StripeClient($stripeSecret);


        $payment=$stripe->paymentIntents->create(
          [
            'currency' => 'usd',
            'amount' => $amount*1000,
            'payment_method_types' => ['card'],
          ]
        );

        return response()->json(array('success'=>true,'data'=>$payment));

        /*\Stripe\PaymentIntent::create([
          'amount' => 1099,
          'currency' => 'usd',
          'payment_method_types' => ['card'],
        ]);*/


    }
     public function index($id='')
    {
        return view("order_transactions.index")->with('id',$id);
    }
}
