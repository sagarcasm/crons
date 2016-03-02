<?php

class Blog_ga_checker extends MX_Controller 
{
  var $conn = "";
  var $server_conn    = array();
  var $server_id = "";     //server id from where cron is executed
  var $ser_id1 = 551;      // Defaceau
  var $ser_id2 = 266;      // OMEGA
  var $ser_id3 = 292;      // OMEGA2
  
  function __construct()
  {
    parent::__construct();    
    //helper    
    $this->load->helper(array('common_function_helper','dashboard_cron', 'phpmailer', 'stdlib'));
    //library
    $this->load->library("ssh");    
  }

  function index()
  {
    $this->blog_checker();
  }

  function blog_checker()
  {
    $data = array();
    $domain_list = array();
    $domain_ga = array();
    $domain_issues = array();
    $blog = array();
    $error_ga = FALSE;
    
    //get domain list for exasite, freebie, microsite,    
    $domain_list = $this->domain_list();
    
    $this->connect_ssh();
    $check = strpos($this->conn, "ERROR");
    if( $check === FALSE )
    {
      if(is_array($domain_list) && count($domain_list) > 0 )
      {
        foreach($domain_list as $values)
        {
          $return_value = $this->blog_exist($values['domain'], $values['ex_www']);
          
          if($return_value['status'] == TRUE)
          {
            $source = '';
            $run = "elinks ".$return_value['url_link']." -source";
            $source = $this->ssh->execute($run);
            $check4 = strpos($source, "ERROR");
            $check5 = strpos($source, "Unable to retrieve");
            
            if(isset($source) && !empty($source) && $check4 === FALSE && $check5 === FALSE)
            {
              $live_ga = $this->extract_ga($source);
              $error_ga = $this->check_ec_live_ga($source,$live_ga,$values['account_type'],$values['ga_code']);

              if($error_ga == FALSE)
              {
                $domain_issues[$values['domain']]['msg'] = 'Ga code issue';
                $domain_issues[$values['domain']]['domain_id'] = $values['id'];
                $domain_issues[$values['domain']]['url'] = $return_value['url_link'];
              }
              else
              {
                $domain_ga[$values['domain']]['msg'] = 'Ga code present';
                $domain_ga[$values['domain']]['domain_id'] = $values['id'];
                $domain_ga[$values['domain']]['url'] = $return_value['url_link'];
              }
            }
            else
            {
              $msg = $source;
              if(empty($source))
                $msg = 'Source page is blank.';

              $domain_issues[$values['domain']]['msg'] = $msg;
              $domain_issues[$values['domain']]['domain_id'] = $values['id'];
              $domain_issues[$values['domain']]['url'] = $return_value['url_link'];
            }
          }
          else
          {            
            $blog[$values['domain']]['domain_id'] = $values['id'];
          }
        }
      }
    }
    $data['ga'] = $domain_ga;
    $data['issues'] = $domain_issues;
    $data['blog'] = $blog;
    
    //insert into blog_ga_checker table
    //$this->insert_table($data);
    
    $subject = "Blog GA check - blog with issue(".count($domain_issues).") ";
    $body = $this->load->view('blog_ga_template' , $data, TRUE);
    $recepients = array('Test EC' => 'testec@');
    $ccc = array();
    
    send_mail($subject, $body, $recepients, $ccc, '', '', "ExaCare", "allit@");
  }
  
  function domain_list()
  {    
    //get domain list for exasite, freebie, microsite,
    
    $sql = "SELECT id, domain, ga_code, account_type, ex_www
            FROM `domains`
            WHERE launched ='1'
            AND NOT FIND_IN_SET( '10', account_type )
            AND NOT FIND_IN_SET( '21', account_type )
            AND (
                  FIND_IN_SET( '0', account_type ) OR 
                  FIND_IN_SET( '18', account_type ) OR 
                  FIND_IN_SET( '9', account_type )
                )
            ";
    $res = $this->db->query($sql)->result_array();   

    return $res;
  }

  function blog_exist($domain_name, $ex_www ='') 
  {
    $prepend =  '';
    if($ex_www !='' && $ex_www =='no')      $prepend = 'www.';
    
    $data = array();
    $data['status'] = FALSE;
    $match_data = "200 OK";
    $command = "curl -I blog.".$prepend.$domain_name;
    $command2 = "curl -I ".$prepend.$domain_name."/blog/";   
    
    $var_Data = $this->ssh->execute($command);

    $check = strpos($var_Data,$match_data);
    if($check !== FALSE)
    {
      $data['status'] = TRUE;
      $data['url_link'] = 'blog.'.$prepend.$domain_name;
    }
    else
    {
      $var_Data2 = $this->ssh->execute($command2);
      $check2 = strpos($var_Data2, $match_data);
      if($check2 !== FALSE)
      {
        $data['status'] = TRUE;
        $data['url_link'] = $prepend.$domain_name.'/blog/';
      }      
    }
    return $data;
  }
  
  function extract_ga($source) 
  {
    $live_ga = array();
    preg_match_all("/UA-[0-9\-]+/", $source, $matches);
    preg_match_all("/MO-[0-9\-]+/", $source, $matches1);
    $matches[0] = array_merge($matches[0], $matches1[0]);
    $matches = $matches[0];
    if(count($matches) > 0)
    {      
      $live_ga = trim_array($matches);
    }
    $live_ga = array_unique($live_ga);
    return $live_ga;
  }
  
  function check_ec_live_ga($source,$live_ga,$acc_type,$ga_code)
  {
    $failed = FALSE;
    $err_ga = 1;
    
    if($acc_type == 15)    
    {
      if(count($live_ga) > 0)
      {
        $failed  = TRUE;
      }
    }
    else
    {
      if(!empty($ga_code))
      { 
        $on_live = 0; 
        $no_ga = 0; 
        $ec_data = explode(",", $ga_code);        
        $ec_data = trim_array($ec_data);
        $ec_data = array_unique($ec_data);
        $cnt_ec_ga = count($ec_data);
        
        if($cnt_ec_ga > 0)
        {
          foreach($ec_data as $val_ec_ga)
          {
            $val_ec_ga = trim($val_ec_ga);
            if(!empty($val_ec_ga))
            {
              if(in_array($val_ec_ga, $live_ga))
                $on_live = 1;
              else
              {
                if(!preg_match("/".$val_ec_ga."/", $source))
                  $err_ga = 1;
              }
            }
            else
              $no_ga++;
          }
          //if ga not stored in EC
          if($no_ga == $cnt_ec_ga)
            $err_ga = 1;
        }
        else
          $err_ga = 1;

        //all live ga present in EC
        if($on_live == 1)
          $err_ga = 0;            
      }
      else
      {
        $err_ga = 1;
      }
    }
    if($err_ga == 0)
      $failed = TRUE;
    return $failed;
  }
  
  function connect_ssh()
  {    
    $this->conn  ='';
    $server_id = $this->random_server();
    
    if(is_array($this->server_conn) && count($this->server_conn) > 0)
    {
      $this->ssh->ip          = $this->server_conn[$server_id]['ip'];
      $this->ssh->username    = $this->server_conn[$server_id]['username'];
      $this->ssh->password    = $this->server_conn[$server_id]['password'];
      $this->conn             = $this->ssh->connect();
    }
  }
  
  function random_server()
  {
    $server_id = 'blank';
    $deface_serv = array();
    
    $sql_server = "SELECT S.id, S.server_name, S.server_username, S.server_password, IP.ip_address AS ip
                    FROM servers S
                    INNER JOIN ip_addresses IP ON ( IP.server_id = S.id ) 
                    WHERE FIND_IN_SET( '44', tags ) 
                    AND IP.status =  'y'
                    AND IP.main_ip =  'y'
                    GROUP BY S.server_name";
    $res = $this->db->query($sql_server)->result_array();    
    
    if (is_array($res) && count($res) > 0 )
    {
      foreach($res as $data)
      {
        //$this->server_conn[$data["id"]]             = $data;
        $this->server_conn[$data["id"]]["ip"]       = $data["ip"];
        $this->server_conn[$data["id"]]["username"] = aes_decrypt($data["server_username"]);
        $this->server_conn[$data["id"]]["password"] = transform($data["server_password"], KEY);
        
        if($data["id"] != $this->ser_id2 || $data["id"] != $this->ser_id3)
          $deface_serv[] = $data["id"];
      }

      $server_id = $deface_serv[array_rand($deface_serv, 1)];      
      return $server_id;
    }
  }
  
  function insert_table($data=array())
  {
    if(is_array($data) && count($data) > 0)
    {
      $cdate = date('Y-m-d H:i:s');
      $sql_4 = "INSERT INTO blog_ga_checker(`domain_id`, `domain`, `report`, `created`) VALUES ";
      
      foreach($data['issues'] as $domain => $val)
      {
        $sql_4 .= "('".$val['domain_id']."', '".$domain."', '".$val['msg']."', '".$cdate."'), ";
      }
      
      foreach($data['ga'] as $domain => $val)
      {
       $sql_4 .= "('".$val['domain_id']."', '".$domain."', '".$val['msg']."', '".$cdate."'), ";
      }
      
      $sql_4 = substr($sql_4, 0 , strrpos($sql_4, ","));
      
      //echo '<br/>'.$sql_4.'<br/>';
      $this->db->query($sql_4);
    } 
  }  
}
?>