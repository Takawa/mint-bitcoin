<?php
   /**
   *  Pre-requisites:
   *     php_curl enabled on web server (with the right binaries)
   *     php_openssl enabled on web server
   *
   *
   *
   *
   *
   *
   *
   *
   */
   class URLScrape {
      private $ch;
      private $timeout = 15; // default
      private $url_referer;
        
      public $response;

      public function __destruct() {
         curl_close($this->ch);
      }

      public function __construct($url = null, $post = null, $headers = null) {
         $cookie = tempnam ("/tmp", "CURLCOOKIE");
         $cookie = "cookie.txt";
         $ch = curl_init();

         curl_setopt ( $ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT'] );
         // curl_setopt( $ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.1; rv:1.7.3) Gecko/20041001 Firefox/0.10.1" );
         curl_setopt( $ch, CURLOPT_COOKIEJAR, $cookie );
         curl_setopt( $ch, CURLOPT_COOKIEFILE, $cookie );
         curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false);  // accept all SSL certs
         curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false);  // accept all SSL certs
         curl_setopt( $ch, CURLOPT_SSLVERSION, 3);          // screw mint.com... and they're TLS-only SSL.  and screw PHP and they're not-well-documented curlopt
         
         curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );  // follows location refer
         curl_setopt( $ch, CURLOPT_ENCODING, "" );
         
         curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );  // doesn't output result, just returns string
         curl_setopt( $ch, CURLOPT_AUTOREFERER, true );     // sets referrer
          
         curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $this->timeout );
         curl_setopt( $ch, CURLOPT_TIMEOUT, $this->timeout );
         curl_setopt( $ch, CURLOPT_MAXREDIRS, 10 );

         $this->ch = $ch;

         if (!empty($url)) $this->URLFetch($url, $post, $headers);
      }
        
      public function URLFetch($url, $post = null, $headers = null) {
         // reset to GET
         curl_setopt( $this->ch, CURLOPT_HTTPGET, true );
 
         // set the referrer if this isn't the first fetch
         if (isset($this->url_referer)) curl_setopt($this->ch,CURLOPT_REFERER,$this->url_referer); 
         $this->url_referer = $url;

         if (!empty($post)) {
            $post_string = '';
            //url-ify the data for the POST
            foreach($post as $key=>$value) { $post_string .= $key.'='.$value.'&'; }
            $post_string = rtrim($post_string, '&');

            // set the post options for curl
            curl_setopt( $this->ch, CURLOPT_POST, count($post) );
            curl_setopt( $this->ch, CURLOPT_POSTFIELDS, $post_string );
            curl_setopt( $this->ch, CURLOPT_POST, true );
         }

         if (empty($headers)) $headers = array();
         //$headers[] = 'Content-type: charset=utf-8'; 
         //$headers[] = 'Connection: Keep-Alive';
         curl_setopt( $this->ch, CURLOPT_HTTPHEADER, $headers );
         
         $url = str_replace( "&amp;", "&", urldecode(trim($url)) );
         curl_setopt($this->ch, CURLOPT_URL, $url);

         $output = curl_exec($this->ch);
         $response = curl_getinfo($this->ch);
         $error = curl_error($this->ch);
         if (!empty($error)) {
            echo 'CONNECTION ERROR: ' . $error;
         }
         //print_r($response);

         $this->response = $output;
         return $output;
      }
        
      public function GetElementValueByID($element_id, $html = null) {
         if (empty($html)) $html = $this->response;
           
         $dom = new DOMDocument();
         $dom->loadHTML($html);
         $el = $dom->getElementById($element_id);
         return $el->getAttribute('value');
      }
   }
   


?>
