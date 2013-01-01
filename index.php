<?php 

  function my_warning_handler($errno, $errstr,$errfile,$errline) {
    if(error_reporting()){
      throw new WarningFault($errstr,$errno,$errfile,$errline);
    }
  }
  set_error_handler("my_warning_handler", E_ALL); 

  /* Main Moni Class
   * 
   */
  class Moni{
    private $file_options = 'options.inc.php';
    private $status;
    private $debug;
    private $path_logs;
    private $file_status;

    //----------------------------------------------------
    function __construct(){
      foreach(file($this->file_options) as $opt){
        if(preg_match('/([a-z0-9\.\_\-]*)=(.*)/',$opt,$res)){
          if($res[1] == 'services'){
            $this->services_defaults = $this->json2obj($res[2]);  
          }else{
            $this->hosts[$res[1]] = $this->json2obj($res[2]); 
          //break;
          }
        }  
      };
      $this->status = new stdClass();
      $this->path_logs = dirname(__FILE__).'/logs/';
      $this->file_status = sys_get_temp_dir().'/moni_temp_status';
        
      ini_set('default_socket_timeout', 3);
      $this->expand();
    }
    
    //----------------------------------------------------
    private function expand(){
      foreach($this->hosts as $host_name=>&$host_options){
        
        if(!isset($host_options->services)){
          continue;
        }
        foreach($host_options->services as $ind=>&$service){
          
          if(is_object($service)){
            /* details */
            foreach($service as $service_name=>$service_options){}
          }else{            
            $service_name = $service;
            $service_options = new stdClass();
          }
          
          $host_service = new MoniHostService(); 
          if(isset($this->services_defaults->$service_name)){
            /* has defaults */
            $host_service->set($service_name,$this->services_defaults->$service_name);
          }
          $host_service->set($service_name,$service_options);
          
          $host_options->t_services[$service_name] = $host_service;

        }
        $host_options->services = $host_options->t_services;
        unset($host_options->t_services);
      }
    }
    
    //----------------------------------------------------
    public function runCheck(){
      foreach($this->hosts as $host_name=>$host_options){
        foreach($host_options->services as $service_name=>$service_options){
          $this->output($host_name.'['.$service_name.'] => ');
          try{
            $status = $this->checkService($host_name,$service_options);
            $errmsg = false;
          }catch(Monifault $e){
            $status = 0;
            $errmsg = $e->getMessage();
          }
          $this->setStatus($host_name,$service_name,$status);
          $this->output($status.' '.$errmsg."\n");
        }
      }
      $this->saveStatus();
    }  

    //----------------------------------------------------
    public function show(){
      $services = Array();
      foreach($this->hosts as $host_name=>$host_options){
        foreach($host_options->services as $service_name=>$service_options){
          $services[$service_name] = 1;
        }
      }
      $current_status = $this->readStatus();
      $result = new stdClass();
      $result->services = $services;
      $result->status = $current_status;
      $result->last_check = date('Y-m-d H:i:s',filemtime($this->file_status));
      
      return json_encode($result);
    }  
    
    //----------------------------------------------------
    function checkService($host_name,$service_options){

      $fp = @fsockopen("tcp://".$host_name,$service_options->port,$errno,$errstr,$service_options->socket_timeout);
      if(!$fp) {
        throw new MoniFault($errstr);
      }
      fclose($fp);
      if($service_options->url){
        try{
          $res = file_get_contents("http://$host_name".$service_options->url);
        }catch(Exception $e){
          throw new MoniFault($e->getMessage());
        }
        if($service_options->test != $res){
          throw new MoniFault($service_options->test." != $res");
        }
      }    
      return 1;
    }

    //----------------------------------------------------
    function setStatus($host_name,$service_name,$status,$e=false){
      if(!isset($this->status->$host_name)) $this->status->$host_name = new stdClass();
      
      $this->status->$host_name->$service_name = new stdClass();
      $this->status->$host_name->$service_name->status = $status;
      $this->status->$host_name->$service_name->errmsg = $e;
    }
    
    //----------------------------------------------------
    function readStatus(){
      if(!file_exists($this->file_status)) return new stdClass();
      return json_decode(file_get_contents($this->file_status));
    }
    
    //----------------------------------------------------
    function saveStatus(){
      $old_status = $this->readStatus();
      file_put_contents($this->file_status,json_encode($this->status));
      foreach($this->status as $host_name=>$host){
        foreach($host as $service_name=>$service){
          if(isset($old_status->$host_name->$service_name)){
            /* has old record*/
            if($old_status->$host_name->$service_name->status != $service->status){
              /* has status change */
              $this->writeLogEntry($host_name,$service_name,$service->status);
            }  
          }else{
            $this->writeLogEntry($host_name,$service_name,$service->status);
          }
        }        
      }
    }
    
    //----------------------------------------------------
    private function writeLogEntry($host_name,$service_name,$status){
      if(!is_dir($this->path_logs)){
        mkdir($this->path_logs);  
      }
      
      $line = date('Y-m-d H:i:s').'-'.(int)$status."\n";
      file_put_contents($this->path_logs.'/'.$host_name.'-'.$service_name.'.log',$line,FILE_APPEND);
    }

    //----------------------------------------------------
    function json2obj($json){
      if(!preg_match('/^{/',$json)){
        $json = "{".$json."}";  
      }
      $json = str_replace(array("\n","\r"),"",$json);
      $json = str_replace("'",'"',$json);
      $json = preg_replace('/([{,]+)(\s*)([^"]+?)\s*:/i','$1"$3":',$json); 

      if(!($s=json_decode($json))){
        throw new Exception('Fail JSON to object:'.$json);
      }  
      return $s;
    }
    
    function output($line){
      echo $line;
    }  

    function debug($line){
      $this->debug[] = $line;
    }  
  }
  
  class MoniHostService{
    public $service_name;
    public $port;
    public $url;
    public $socket_timeout = 2;
    public $retry;  
    public $test=1;

    function set($service_name,$service_options){
      if(!is_object($service_options)){
        $t = new stdClass();
        $t->port = $service_options;
        $service_options = $t;
      }
      $this->service_name = $service_name;
      foreach($this as $p=>$v){
        if(isset($service_options->$p)){
          $this->$p = $service_options->$p;
        }        
      }
    }
  }
  
  //------------------------------------------------------------------------------
  class MoniFault extends Exception{}
  
  //------------------------------------------------------------------------------
  class WarningFault extends Exception{
    public function __construct($message, $code,$file,$line) {
      $this->file = $file;
      $this->line = $line;
      $this->is_fatal_error = 1;
      parent::__construct($message, $code);
    }
  }

  //set_exception_handler('exception_handler');
  
  
  $m = new Moni();
  if(php_sapi_name()==="cli"){
    $m->runCheck();
    exit;
  }else if(isset($_GET['result'])){
    echo $m->show();
    exit;
  }

?><html>
  <head>
    <title>Moni</title>
    <style>
      #table_hosts{border-top:1px solid #ddd;border-left:1px solid #ddd}
      #table_hosts td{border-bottom:1px solid #ddd;border-right:1px solid #ddd;padding:5px;}
      #table_hosts td.green{background:#0f0;}
      #table_hosts td.red{background:#f00;}
    </style>
    <script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.8.2/jquery.min.js"></script>
    <script>
      $(document).ready(function() {
        jQuery.getJSON( '?result=1',false,function(data, textStatus, jqXHR){
          html = '<tr><td>host</td>';
          for(s in data.services){
            html += '<td>' + s + '</td>';
          }
          html += '</tr>';
          $('#table_hosts').append( html);
          for(h in data.status){
            var host = data.status[h];
            var html = '<tr>';
            html += '<td>' + h + '</td>';
            for(s in data.services){
              
              if(host[s]){
                html += '<td class="'+(host[s].status==1?'green':'red')+'">'+(host[s].status?'ok':'problem')+'</td>';
              }else{
                html += '<td>';
                html += '</td>';
              }
            }             
            html += '</tr>'; 
            $('#table_hosts').append( html);
          }
        })
      });
    
    </script>
  </head>
  <body>
    <h1>Moni</h1>
    <table id="table_hosts" cellpadding="0" cellspacing="0" >
    </table>
  </body>
</html>
