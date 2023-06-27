<?php
use \ondrs\Comgate\AgmoPaymentsSimpleProtocol;

/**
 *
 */
class EDD_Comgate
{
  const PAID = 'PAID';
  const CANCELLED = 'CANCELLED';
  const PENDING = 'PENDING';
  const AUTHORIZED = 'AUTHORIZED';

  private   $LOG_NAME = 'edd_comgate.log';
  private   $DEBUG_MODE = false;
  private $comgate_client;
  private $PAYMENTS_URL = 'https://payments.comgate.cz/v1.0/create';

  public function __construct($debug = false)
  {

    require __DIR__ . '/vendor/autoload.php';
    $this->DEBUG_MODE = $debug;
    $this->hooks();
  }

  public function hooks(){
    add_filter( 'edd_payment_gateways', array($this,'register_gateway') );
    add_action('edd_comgate_cc_form', array($this,'cc_form'));
    add_action('edd_gateway_comgate', array($this,'process_payment') );
    add_filter( 'edd_settings_gateways', array($this,'settings') );
    add_action('init', array($this,'listen_for_pingback'));

  }



  public function process_payment($purchase_data){
      $payment = edd_insert_payment( $purchase_data );
      $this->initComgate();
      $edd_payment = new EDD_Payment($payment);

      $createPayment = $this->comgate_client->createTransaction(
        'CZ', //country
        (float)$edd_payment->total, //price
        $edd_payment->currency, //currency
        $this->setProdName('Obj. ' .$payment. ' '.get_bloginfo('name')), //label, max 16 characters
        $payment, //merchants payment identifier
        $edd_payment->first_name . ' ' .$edd_payment->last_name, // payer identifier
        null, //one of VATs PL from Agmo Payments system parameter is required only for MPAY_PL method
        null, //product category identifier parameter is required only for MPAY_CZ and SMS_CZ methods
        'ALL', //method identifier or 'ALL' value
        null, //Identifier of Merchant’s bank account to which AGMO transfers the money. If the parameter is empty, the default Merchant’s account will be used.
        $edd_payment->email, //cliens email address (optional)
        null, //clients phone number (optional)
        'Obj. ' .$payment. ' '.get_bloginfo('name'), // product identifier (optional)
        null, //language identifier (optional)
        false, //$preauth
        false, //is Recurring
        null //$reccurringId
      );
      $redirectUrl = $this->comgate_client->getRedirectUrl();
      edd_empty_cart();
      wp_redirect($redirectUrl);
      exit;
  }

  public function settings($settings){
    $comgate_settings = [
      [
        'id' => 'comgate_settings',
  			'name' => '<strong>' . __( 'Nastavení Comgate', 'gopay' ) . '</strong>',
  			'desc' => __( 'Nastavte parametry platební brány', 'pw_edd' ),
  			'type' => 'header'
      ],
      [
        'id' => 'test_merchantid',
  			'name' => '<strong> ' . __( 'Test Merchant ID', 'gopay' ) . '</strong>',
  			'desc' => __( 'Test Merchant ID:', 'gopay' ),
  			'type' => 'text',
  			'size' => 'regular'
      ],
      [
        'id' => 'test_password',
  			'name' => '<strong> ' . __( 'Test Heslo', 'gopay' ) . '</strong>',
  			'desc' => __( 'Test Heslo:', 'gopay' ),
  			'type' => 'text',
  			'size' => 'regular'
      ],
      [
        'id' => 'prod_merchantid',
  			'name' => '<strong> ' . __( 'Merchant ID', 'gopay' ) . '</strong>',
  			'desc' => __( 'Merchant ID:', 'gopay' ),
  			'type' => 'text',
  			'size' => 'regular'
      ],
      [
        'id' => 'prod_password',
  			'name' => '<strong> ' . __( 'Heslo', 'gopay' ) . '</strong>',
  			'desc' => __( 'Heslo:', 'gopay' ),
  			'type' => 'text',
  			'size' => 'regular'
      ],
    ];
    return array_merge( $settings, $comgate_settings );
  }

  public function listen_for_pingback(){
    if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'comgate' ) {
  		$this->process_return();
  	}

  }

  public function register_gateway($gateways){
    $gateways['comgate'] = [
      'admin_label' => 'Comgate',
      'checkout_label' => __( 'Online platební karta nebo převod (ihned)', 'eddcomgate' )
     ];
  	return $gateways;
  }

  public function process_return(){
    $data = $_POST;

    $payment_id = $data['refId'];
    $this->initComgate();

    if (isset($_GET['cmg-status'])){
      $this->user_returning_back($_GET['cmg-status']);
    }

    try{
      $result = $this->comgate_client->checkTransactionStatus($data);
    }catch (Exception $e){
      $this->debug_to_console('loading through GET');
      echo 'code=1&message='.urlencode($e->getMessage());
      die();
    }
    if ($data['status'] == self::PAID){
      edd_update_payment_status( $payment_id, 'publish' );
      //edd_send_to_success_page();
    }else{
      $location = get_permalink($edd_options['failure_page']);
      //wp_redirect($location);
      //exit;
    }

    echo 'code=0&message=OK';
    die();
  }

  public function user_returning_back($cmg_status){
    $location = get_permalink($edd_options['failure_page']);
    if ($cmg_status == self::PAID){
      edd_send_to_success_page();
    }else{
      wp_redirect($location);
      exit;
    }
  }

  public function cc_form(){
    return;
  }

  public function setDebug($debug){
    $this->DEBUG_MODE = $debug;
  }

  public function initComgate(){
    global $edd_options;
    if (!empty($this->comgate_client)){
      return;
    }
    if ($this->isTestMode()){
      $this->comgate_client = new AgmoPaymentsSimpleProtocol($this->PAYMENTS_URL,$edd_options['test_merchantid'],true,$edd_options['test_password']);
    }else {
      $this->comgate_client = new AgmoPaymentsSimpleProtocol($this->PAYMENTS_URL,$edd_options['prod_merchantid'],false,$edd_options['prod_password']);
    }
  }

  public function setLogName($logName){
    $this->LOG_NAME = $logName;

  }

  public function isTestMode(){
    return edd_is_test_mode();
  }

  public function setProdName($string,$length=16,$dots='…'){
      //https://stackoverflow.com/a/3161830/855636
      return (strlen($string) > $length) ? substr($string, 0, $length - strlen($dots)) . $dots : $string;
  }

  public function debug_to_console( $data) {
      $output = $data;
      $logforreal = $this->DEBUG_MODE;
      if(!$logforreal){
        return;
      }
      if ( is_array( $output ) ){
          $output = implode(', ', array_map(
              function ($v, $k) {
                  if (is_object($v)){
                      $v = serialize($v);
                  }
                  return sprintf("%s='%s'", $k, $v);
               },
              $output,
              array_keys($output)
          ));
      }
      if (is_object($output)){
          $output = serialize($output);
      }

      error_log($output ."\n", 3, $this->LOG_NAME);


  }
}


 ?>
