<?php
// generic class to curl for URLs
require 'classes/urlscrape.class.php';

// generic class to do some debugging
require 'classes/debug.class.php';

/**
 * Logs in to mint.com using URLScrape class and gets the mint javascript token
 * that's used for securing subsequent requests
 * @param $session   A URLScrape session, which is used to re-use the curl object
 * @param $username  Mint.com username (email address)
 * @param $password  Mint.com password
 * @return  string   Javascript token (string)
 */
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

/**
 * Gets the account ID for the account that we'll update
 * @param $session      A URLScrape session, which is used to re-use the curl object
 * @param $token        Javascript token that mint uses internally to secure requests
 * @param $account_name Label for the mint account we're looking for
 * @return  int         Mint accountID that matches the account name
 */
function mint_get_account($session, $token, $account_name) {
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
   
   // 115485 seems to be a magic/arbitrary number that mint labels the object in it's return json
   foreach ($_obj->response->{115485}->response as $account) {
      if ($account->name == $account_name) {
         return $account->accountId;
      }
   }
   Debug::trace('could not find an account named ' . $account_name . ' in your mint.com account');
}

/**
 * Updates a specific account in mint to the provided value
 * The account needs to be:
 *    Other
 *    A "cash" account
 *    Not be associated with a loan
 *    Active
 * @param $session         A URLScrape session, which is used to re-use the curl object
 * @param $token           Javascript token that mint uses internally to secure requests
 * @param $account_id      Mint account ID that'll be updated
 * @param $account_name    Mint account name that matches that account ID
 * @param $total           The value of the assets in the account
 */
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

/**
 * Gets the 24h price from blockchain.info
 * @return  double   The price
 */
function blockchain_get_price_24h() {
   $_o_url = new URLScrape("http://blockchain.info/q/24hrprice");
   return $_o_url->response;
}

/**
 * Gets the total coins in a wallet or set of wallets from blockchain.info
 * Addresses should be delimited by commas (i.e. abd123,def456)
 * A wallet address can be ignored by prefixing it with a hash (i.e. #abd123)
 * @param $addresses    Comma-delimited list of wallet addresses
 * @return  double      Aggregate total of all wallets
 */
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

/**
 * Generic method to read from Bitfinex's API
 * Handles authentication and all of the hashing crap that bitfinex requires
 * see https://www.bitfinex.com/pages/api for API docs
 * see https://community.bitfinex.com/showwiki.php?title=Sample+API+Code for Python and NodeJS API examples (which are crap)
 * @param $key       Bitfinex API key
 * @param $secret    Bitfinex API secret
 * @param $command   Command for Bitfinex API
 * @param $auth      Bool for whether or not auth is required
 * @return JSON      Object from Bitfinex
 */
function bitfinex_generic_read($key, $secret, $command, $auth = true) {
   // Bitfinex requires an incrementing unique number, so we'll take it from the time
   $_mt = explode(' ', microtime());

   // authenticated requests need all these headers
   if ($auth) {
      $_post = array(
         "nonce"              => number_format($_mt[1]*100000,0,'',''),
         "request"            => '/v1/' . $command,
      );
      
      // the payload needs to be base64 (not documented)
      $payload = base64_encode(json_encode($_post));
      
      // and the signature needs to be hex
      $sign = bin2hex(hash_hmac('sha384', $payload, $secret, true));
      
      $_headers = array (
         'X-BFX-APIKEY:' . $key,
         'X-BFX-PAYLOAD:'  . $payload,
         'X-BFX-SIGNATURE:' . $sign
      );
   }
   $_o_url = new URLScrape("https://api.bitfinex.com/v1/" . $command, null, $_headers);
   return json_decode($_o_url->response);
}

/**
 * Get /balance API response from Bitfinex
 * @param $key       Bitfinex API key
 * @param $secret    Bitfinex API secret
 * @return JSON      Object from Bitfinex
 */
function bitfinex_get_balances($key, $secret) {
   // has stuff like available funds...
   return bitfinex_generic_read($key, $secret, 'balances');
}

/**
 * Get total balance of coins in Bitfinex
 * This will retrieve ALL balances, not just available balances (i.e. coins scheduled for trade)
 * @param $key       Bitfinex API key
 * @param $secret    Bitfinex API secret
 * @return  double   Total coins in all Bitfinex accounts (trading, exchange, etc.)
 */
function bitfinex_get_total_coin_value($key, $secret) {
   $balances = bitfinex_get_balances($key, $secret);
   $_total = 0;
   foreach ($balances as $balance) {
      if ($balance->currency == 'btc') {
         $_total += doubleval($balance->amount);
      }
   }
   return $_total;
}

/**
 * Get total balance of USD in Bitfinex
 * This will retrieve ALL balances, not just available balances
 * @param $key       Bitfinex API key
 * @param $secret    Bitfinex API secret
 * @return  double   Total USD in all Bitfinex accounts (trading, exchange, etc.)
 */
function bitfinex_get_total_usd_value($key, $secret) {
   $balances = bitfinex_get_balances($key, $secret);
   $_total = 0;
   foreach ($balances as $balance) {
      if ($balance->currency == 'usd') {
         $_total += doubleval($balance->amount);
      }
   }
   return $_total;
}

/**
 * Generic method to read from btc-e's API
 * Handles authentication
 * @param $key       Btc-e API key
 * @param $secret    Btc-e API secret
 * @param $command   Command for Btc-e API
 * @return JSON      Object from Btc-e
 */
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

/**
 * Get /getInfo API response from Btc-e
 * @param $key       Btc-e API key
 * @param $secret    Btc-e API secret
 * @return JSON      Object from Btc-e
 */
function btce_account_info($key, $secret) {
   // has stuff like active funds...
   return json_btce_generic_read($key, $secret, 'getInfo');
}

/**
 * Get /ActiveOrders API response from Btc-e
 * @param $key       Btc-e API key
 * @param $secret    Btc-e API secret
 * @return JSON      Object from Btc-e
 */
function btce_active_orders($key, $secret) {
   // has stuff like active funds...
   return btce_generic_read($key, $secret, 'ActiveOrders');
}

/**
 * Get total balance of coins in Btc-e that are actively on a sell order, which won't show up in your Btc-e wallet total
 * Use this in conjunction with your Btc-e wallet address, which will show what coins are currently "available"
 * @param $key       Btc-e API key
 * @param $secret    Btc-e API secret
 * @return  double   Total coins in active Btc-e sell orders
 */
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
// show errors if they happen
error_reporting(E_ERROR);

if (!empty($_POST['username']) && !empty($_POST['password'])) {
   // get post info
   $username = $_POST['username'];
   $password = $_POST['password'];
   $account = $_POST['account'];
   $addresses = $_POST['addresses'];
   
   $btce_key = $_POST['btcekey'];
   $btce_secret = $_POST['btcesecret'];

   $bfx_key = $_POST['bfxkey'];
   $bfx_secret = $_POST['bfxsecret'];

   // get the current price of bitcoin (we like the most recent price from bitfinex best)
   $price = blockchain_get_price_24h();
   $bfx_price = bitfinex_generic_read($bfx_key, $bfx_secret, 'ticker/btcusd', false);
   Debug::trace('blockchain 24h btc price at ' . $price);
   Debug::trace('btfinex current price at ' . $bfx_price->last_price);
   $price = $bfx_price->last_price;
   
   // calculate total coins
   $total = 0;
   $total_usd = 0;
   
   // first, get all wallet addresses
   $totals = blockchain_get_address_totals($addresses);
   foreach ($totals as $address => $amount) {
      Debug::trace('found bitcoin wallet ' . $address . ' with ' . $amount . ' coins');
      $total += $amount;
   }
   Debug::trace('found ' . $total . ' bitcoins in all wallets');
   
   // if we've got bitfinex API info, get all coins from bitfinex
   if (!empty($bfx_key) && !empty($bfx_secret)) {
      $bitfinex_total = bitfinex_get_total_coin_value($bfx_key, $bfx_secret);
      $bitfinex_usd = bitfinex_get_total_usd_value($bfx_key, $bfx_secret);
      Debug::trace('found bitfinex total balance of ' . $bitfinex_total . ' bitcoins and $' . $bitfinex_usd);
      $total += $bitfinex_total;
      $total_usd += $bitfinex_usd;
   }

   // and if we've got btc-e API info, get active sell orders from btc-e
   if (!empty($btce_key) && !empty($btce_secret)) {
      $btce_total = btce_get_active_order_total($btce_key, $btce_secret);
      Debug::trace('found btc-e pending orders for ' . $btce_total . ' bitcoins');
      $total += $btce_total;
   }
   
   $session = new URLScrape();
   
   // and then log into mint, get the account, and update it
   Debug::trace('logging in to mint...');
   $token = mint_login($session, $username, $password);

   Debug::trace('getting your bitcoin account info from mint...');
   $account_id = mint_get_account($session, $token, $account);
   
   Debug::trace('updating your bitcoin account info on mint...');
   $value = number_format($price * $total + $total_usd,2);
   echo $total . ' BTC at ' . $price . ' for a total of $' . $value;
   mint_update_account($session, $token, $account_id, $account, $value);
   
   unset($session);
}
?>