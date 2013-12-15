<?php
   require 'classes/urlscrape.class.php';
   require 'classes/debug.class.php';

   function mint_login($session, $username, $password)
   {
      // mint needs some info to log in
      $_post = array(
         "username"  => $username,
         "password"  => $password,
         "task"      => "L", 
         "nextPage"  => "",
      );
      $session->URLFetch("https://wwws.mint.com/loginUserSubmit.xevent", $_post);
        
      // and mint gives us a token to use
      return $session->GetElementValueByID("javascript-token");
   }
   
   function mint_get_bitcoin_account($session, $token, $account_name) {
      // mint needs some info to log in
      $_post = array(
         "input"        => '[' . json_encode(array(
            "args"      => array(
               "types"  => array("OTHER_PROPERTY"),
            ),
            "id"        => "115485",
            "service"   => "MintAccountService",
            "task"      => "getAccountsSorted", 
         )) . ']',
      );
      $session->URLFetch("https://wwws.mint.com/bundledServiceController.xevent?token=" . $token, $_post);
      $_obj = json_decode($session->response);
      foreach ($_obj->response->{115485}->response as $account) {
         if ($account->name == $account_name) {
            return $account->accountId;
         }
      }
      Debug::trace('could not find an account named ' . $account_name . ' in your mint.com account');
   }
   
   function mint_update_account($session, $token, $account_id, $account_name, $total) {
      $_post = array(
         "accountId"             => $account_id,
         "types"                 => "ot",
         "accountName"           => $account_name, 
         "accountValue"          => $total,
         "associatedLoanRadio"   => "No", 
         "accountType"           => "3",  
         "accountStatus"         => "1",
         "token"                 => $token,
      );
      $session->URLFetch("https://wwws.mint.com/updateAccount.xevent", $_post);
   }
   
   function blockchain_get_price_24h() {
      $_o_url = new URLScrape("http://blockchain.info/q/24hrprice");
      return $_o_url->response;
   }

   function blockchain_get_address_totals($addresses) {
      $totals = array();
      
      foreach (explode(',',$addresses) as $address) {
         if (substr($address,1,1) != '#') {
            $_o_url = new URLScrape("http://blockchain.info/q/addressbalance/" . $address);
            $totals[$address] = $_o_url->response;
         }
      }
      return $totals;
   }
   
   function bitfinex_generic_read($key, $secret, $command, $auth = true) {
      // this was a pain in the ass - bitfinex is not clear on the type of encoding they expect
      // see https://community.bitfinex.com/showwiki.php?title=Sample+API+Code for examples
      // but it works motherfucker...
      //
      $_mt = explode(' ', microtime());

      if ($auth) {
         $_post = array(
            "nonce"              => number_format($_mt[1]*100000,0,'',''),
            "request"            => '/v1/' . $command,
         );
      
         $payload = base64_encode(json_encode($_post));
         $sign = bin2hex(hash_hmac('sha384', $payload, $secret, true));
         $_headers = array (
            'X-BFX-APIKEY:' . $key,
            'X-BFX-PAYLOAD:'  . $payload,
            'X-BFX-SIGNATURE:' . $sign
         );
      }
      $_o_url = new URLScrape("https://api.bitfinex.com/v1/" . $command, null, $_headers);
      // print_r($_o_url->response);
      return json_decode($_o_url->response);
   }
   
   function bitfinex_get_balances($key, $secret) {
      // has stuff like available funds...
      return bitfinex_generic_read($key, $secret, 'balances');
   }
   
   function bitfinex_get_total_value($key, $secret) {
      $balances = bitfinex_get_balances($key, $secret);
      $_total = 0;
      foreach ($balances as $balance) {
         $_total += $balance->amount;
      }
      return $_total;
   }

   function btce_generic_read($key, $secret, $command) {
      $_mt = explode(' ', microtime());
      $_post = array(
         "method"             => $command,
         "nonce"              => $_mt[1],
      );

      $sign = hash_hmac('sha512', http_build_query($_post, '', '&'), $secret);
      $_headers = array (
         'Sign: ' . $sign,
         'Key: '  . $key,
      );

      $_o_url = new URLScrape("https://btc-e.com/tapi/", $_post, $_headers);
      return json_decode($_o_url->response);
   }
   
   function btce_account_info($key, $secret) {
      // has stuff like active funds...
      return json_btce_generic_read($key, $secret, 'getInfo');
   }

   function btce_active_orders($key, $secret) {
      // has stuff like active funds...
      return btce_generic_read($key, $secret, 'ActiveOrders');
   }
   
   function btce_get_active_order_total($key, $secret) {
      $orders = btce_active_orders($key, $secret);
      $_total = 0;
      foreach ($orders->return as $order) {
         if ($order->type == 'sell') {
            $_total += $order->amount;
         }
      }
      return $_total;
   }

?>

Total coins owned will include wallet totals + active sell-trade totals from btc-e and bitfinex.

<form method="post">
   Mint.com: <input type="text" name="username" value="" />
   <input type="password" name="password" value="" /><br />
   Mint acct: <input type="account" name="account" value="" /><br />
   btc-e key: <input type="text" name="btcekey" size="90" value="" /><br />
   btc-e secret:<input type="text" name="btcesecret" size="90" value="" /><br />
   bitfinex key: <input type="text" name="bfxkey" size="90" value="" /><br />
   bitfinex secret:<input type="text" name="bfxsecret" size="90" value="" /><br />
   wallet addresses (separated by comma, prefix a wallet with # to ignore): <br />
   <textarea name="addresses" cols="50" rows="5"></textarea>
   <input type="submit" />
</form>

<?php
   error_reporting(E_ERROR);

   if (!empty($_POST['username']) && !empty($_POST['password'])) {
      $username = $_POST['username'];
      $password = $_POST['password'];
      $account = $_POST['account'];
      $addresses = $_POST['addresses'];
      
      $btce_key = $_POST['btcekey'];
      $btce_secret = $_POST['btcesecret'];

      $bfx_key = $_POST['bfxkey'];
      $bfx_secret = $_POST['bfxsecret'];

      $price = blockchain_get_price_24h();
      $bfx_price = bitfinex_generic_read($bfx_key, $bfx_secret, 'ticker/btcusd', false);
      Debug::trace('blockchain 24h btc price at ' . $price);
      Debug::trace('btfinex current price at ' . $bfx_price->last_price);
      
      $price = $bfx_price->last_price;
      
      $total = 0;
      
      $totals = blockchain_get_address_totals($addresses);
      foreach ($totals as $address => $amount) {
         Debug::trace('found bitcoin wallet ' . $address . ' with ' . $amount . ' coins');
         $total += $amount;
      }
      Debug::trace('found ' . $total . ' bitcoins in all wallets');
      
      if (!empty($bfx_key) && !empty($bfx_secret)) {
         $bitfinex_total += bitfinex_get_total_value($bfx_key, $bfx_secret);
         Debug::trace('found bitfinex total balance of ' . $bitfinex_total . ' bitcoins');
         $total += $bitfinex_total;
      }

      if (!empty($btce_key) && !empty($btce_secret)) {
         $btce_total = btce_get_active_order_total($btce_key, $btce_secret);
         Debug::trace('found btc-e pending orders for ' . $btce_total . ' bitcoins');
         $total += $btce_total;
      }
      
      $session = new URLScrape();
      
      Debug::trace('logging in to mint...');
      $token = mint_login($session, $username, $password);

      Debug::trace('getting your bitcoin account info from mint...');
      $account_id = mint_get_bitcoin_account($session, $token, $account);
      
      Debug::trace('updating your bitcoin account info on mint...');
      $value = number_format($price * $total,2);
      echo $total . ' BTC at ' . $price . ' for a total of $' . $value;
      mint_update_account($session, $token, $account_id, $account, $value);
      
      unset($session);
   }
?>