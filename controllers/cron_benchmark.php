<?php
class Cron_benchmark extends MX_Controller
{
	var $msg              = "";
	var $errors           = "";
	var $mode             = "";
	var $page_name        = "crons";
	var $page_h1          = "Crons";
  var $uri_val          = '';
  var $server_name      = '';
  var $hosts_allow_file = '';
  var $content          = array();
  var $extra_ip         = array();
  var $file_create      = 0;


  function __construct()
	{
		parent::__construct();
		$this->load->model('db_interaction');
		$this->load->helper("phpmailer");
		$this->load->helper("common_function");
		$this->load->helper('custom_values_helper');
		$this->load->library("ssh");
		$this->load->helper('db_encrypt');
		$this->load->helper('stdlib');
		$this->load->helper('server_types');
    $this->load->helper('dashboard_cron');
	}

  function index($uri = '')
	{
		if($uri != "")
      $this->uri_val = $uri;

    $this->benchmark_file_copy();
	}
  
  function benchmark_file_copy()
  {
    $uri_test = $this->uri_val;
    if ($uri_test == '') 
    {
      $testing = 0;        //while testing cron make it 1 else 0
      $cron_id = 192 ;      //entry from dashboard cron table    
      $total_counter  = 15;     //entry from dashboard cron table    
    }
    $data = array();
    
    $from_path = $_SERVER['DOCUMENT_ROOT']."/test/benchmark/bench.php";
    $to_path = "/root/benchmark/bench.php";
    
    $sql = "SELECT id FROM ".TBL_TAGS." WHERE `tag_name` LIKE 'php%'";
    $tag_id         = $this->db_interaction->run_query($sql);
    $cid = array();
    foreach ($tag_id as $value) 
    {
      $cid[] = $value['id'];
    }
    $findset = '';
    $check = 0;
    foreach ($cid as $val)
    {
      $check++;
      $findset .= " FIND_IN_SET (".$val.", s.tags) ";
      if ($check != count($cid))
      {
        $findset .=' OR';
      }
    }
    $include_servers  = "'LINUX', 'External', 'Dedicated - Exa Hosting', 'Disaster Server', 'Exa Hosting', 'Ready Server'";
    $result = "SELECT s.server_username,s.server_password,s.server_name, ips.ip_address ,ips.ip_address_id, s.id
              FROM ".TBL_SERVERS." AS s 
              LEFT JOIN ".TBL_IP_ADDRESS." AS ips ON s.id = ips.server_id
              WHERE s.id IN (".fetch_server($include_servers).")
              AND s.id NOT IN (".fetch_inactive_server_list('INACTIVE/CANCELLED').") 
              AND (".$findset.")
              AND ips.status =  'y' AND ips.main_ip = 'y' 
              AND ips.ip_address_id != ''
              GROUP BY s.id LIMIT 1";
    $result_s = $this->db->query($result)->result_array();
      
    $ip_add_check = '';
    foreach ($result_s as $s_value)
    {
      $server_id			= $s_value['id'];
      $server_name		= ucfirst(strtolower($s_value['server_name']));
      $this->ssh->ip 		      = $s_value['ip_address']; //$ip
      $this->ssh->username 		= aes_decrypt($s_value["server_username"]);  //$username
      $this->ssh->password		= transform($s_value["server_password"],KEY); //$password
      $stream         = $this->ssh->connect(); 
      
      /***********checks*****************/
      $pos   = strpos($stream,"ERROR");
      if ($pos === false) //ssh connected
      {
        $check_file = $this->check_file(); 
        if ($check_file === false) //file not present on server
        {
          //make directory if not present
          $mkdir         = "cd /; mkdir /root/benchmark";
          $dir = $this->ssh->execute($mkdir);
          
          //file transfer
          $res = $this->ssh->execute_ssh2_scp($from_path, $to_path); 
          var_dump($res);
          
          //recheck transfer
          $recheck_file = $this->check_file();
          if ($recheck_file === false) //file not transfered on server
          {
            $data['file_fail'][$server_id]['server_name'] = $s_value['server_name'];
            $data['file_fail'][$server_id]['server_ip'] = $s_value['ip_address'];
          }
          else
          {
            //execute file
            $execute_file_data = $this->execute_file();
            if (!empty($execute_file_data))
            {
              $this->add_data_db($server_id,$execute_file_data,'benchmark_script', $d_id = 0);
              $data['server_data'][$server_id]['server_ip'] = $s_value['ip_address'];
              $data['server_data'][$server_id]['server_name'] = $s_value['server_name'];
              $data['server_data'][$server_id]['data'] = $execute_file_data;
            }
          }
        }
        else //file present on server
        {
          $execute_file_data = $this->execute_file();
          $server_data = explode("\n",$execute_file_data);
          echo 'herere';
          echo '<pre>';
          print_r($server_data);
          echo '</pre>';exit();
          
          
          
          if (!empty($execute_file_data))
          {
            $this->add_data_db($server_id,$execute_file_data,'benchmark_script', $d_id = 0);
            $data['server_data'][$server_id]['server_ip'] = $s_value['ip_address'];
            $data['server_data'][$server_id]['server_name'] = $s_value['server_name'];
            $data['server_data'][$server_id]['data'] = $execute_file_data;
          }
        }
      }
      else //ssh not connected
      {
        $data['ssh_fail'][$server_id]['server_name'] = $s_value['server_name'];
        $data['ssh_fail'][$server_id]['server_ip'] = $s_value['ip_address'];
      }
    } 
    
    if (isset($data) AND !empty($data))
    {
      $recepients = array(
      'Sagar Sawant' => 'sagar.sawant@'
      );
      $subject="Bench marking script for Linux external webservers"; 
      $body  = $this->load->view('email_tpl_header', "", true);
      $body .= $this->load->view('benchmark_report',$data,true);
      $body .= $this->load->view('email_tpl_footer', "", true);
      
      echo $body;exit();
      // send_mail($subject, $body, $recepients, array(), '', '', 'ExaCare', 'allit@', '', '', 1);
      // $cron_array 	= get_cron_data($cron_id, $emails_added, $cron_data, $testing);
    }
  }
  
  function execute_file()
  {
    $execute_file = $this->ssh->execute("cd /; php root/benchmark/bench.php");
    return $execute_file;
  }
  
  function check_file()
  {
    echo '-----file check-----';
    $check_file = $this->ssh->execute("cd /; find root/benchmark/bench.php");
    $check_file_status = strpos($check_file, 'bench.php');
    var_dump($check_file_status);
    return $check_file_status;
  }
  
  function add_data_db($server_id,$execute_file_data,$file_name = 'benchmark_script', $d_id = 0)
  {
    $data = array(
    'server_id' => $server_id,
    'domain_id' => $d_id,
    'file_name' => $file_name,
    'content' => $execute_file_data,
    'created' => date('Y-m-d H:i:s'),
    'created_by' => 531
    );
    echo '----db------';
    echo '<pre>';
    print_r($data);
    echo '</pre>';
    // $this->db->insert(TBL_SERVER_INFO, $data);
  }
}
?>