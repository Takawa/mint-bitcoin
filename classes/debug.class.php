<?php
   $GLOBAL_DEBUG_START = microtime(true);
   $GLOBAL_DEBUG_LAST = microtime(true);

   class Debug {
      public static function trace($message = null) {
         global $GLOBAL_DEBUG_START;
         global $GLOBAL_DEBUG_LAST;

         $render_time = microtime(true) - $GLOBAL_DEBUG_START;
         $last_time = microtime(true) - $GLOBAL_DEBUG_LAST;
            
         //echo '<div style="clear:both; font-family:Courier; margin:30px; color:#333; font-size:11px;">';
         echo date('H:m:s:i',time()) . ' / ' . 'render ' . number_format($render_time,4) . 's / last ' . number_format($last_time,4) . 's<br/>';
         $backtrace = debug_backtrace();
         $backtrace  = $backtrace[0];
         //print_r($backtrace);
         //echo (empty($backtrace['file'])) ? $backtrace['class'] : $backtrace['file'];
         //echo ' / ' . $backtrace['line'] . '<br />';
            
         echo $message;
         //echo '</div>';
         
         ob_flush();
      }
   }

?>
