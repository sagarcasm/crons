<?php 

class Cron_amazon_ec extends MX_Controller {
  
  function __construct()
  {
    parent::__construct();
    $this->load->helper(array('phpmailer', 'db_encrypt', 'stdlib', 'dashboard_cron', 'substituted_functions','array'));
    $this->load->helper('custom_values');
    $this->load->library("myaws");
    $this->load->library('ssh');
  }

  function index() 
  {
    $testing        = 0;       //while testing cron make it 1 else 0
    $cron_id        = 221;       //entry from dashboard cron table
    $total_counter  = 10;

    $query          = "SELECT id,access_key,secret_key,provider_name
                      FROM " . TBL_SERV_PROVIDER . " 
                      WHERE (access_key NOT IN('0' , '') || secret_key NOT IN('0' , ''))";    
    $cron_data      = get_cron_limit($query, $total_counter, $cron_id, $testing);
    $insert_id      = array_key_exists('insert_id',$cron_data) ? $cron_data['insert_id'] : '';
    $providers_list = $this->db->query($cron_data['query'])->result_array();
    
//    $providers_list = $this->db->query($query)->result_array();

    $data = array();
    $region_city_map  = array(
                          'ap-northeast-1'  =>  'Tokyo',
                          'ap-southeast-1'  =>  'Singapore',
                          'ap-southeast-2'  =>  'Sydney',
                          'eu-central-1'    =>  'Frankfurt',
                          'eu-west-1'       =>  'Ireland',
                          'sa-east-1'       =>  'Sao Paulo',
                          'us-east-1'       =>  'North_virginia',
                          'us-west-1'       =>  'N. California',
                          'us-west-2'       =>  'Oregon',
                        );

    foreach ($providers_list as $provider_details)
    {
      $access_key = $provider_details['access_key'];
      $secret_key = $provider_details['secret_key'];
      $region_arr = unserialize(REGION_ARRAY);
      
      if (is_array($region_arr) && !empty($region_arr)) 
      {
        foreach ($region_arr as $region) 
        {
          $account_details = $this->ec_details($access_key, $secret_key, $region,$provider_details['provider_name']);

          if (count($account_details) > 0)
          {
            foreach ($account_details as $instance => $acc)
            {
              $vol_size             = array();
              $server_details       = $this->server_details($acc['instance_id']);
              $server_id            = $server_details['server_id'];
              $ec_server_details    = $this->ec_server($server_id);

              if($server_id != "")
              {
                //----------------------AMAZON DATA---------------------------------------------------------------

                $val[$instance]                     = $acc;

                $data[$server_id]['instance_id']    = $val[$instance]['instance_id'];
                $data[$server_id]['region']         = $region;
                $data[$server_id]['city']           = $region_city_map[$region];
                $data[$server_id]['aws_id']         = $server_details['id'];

                $volume_ids                         = $this->describe_ec2_instances($acc['instance_id']);

                if(is_array($volume_ids) && count($volume_ids) > 0)
                {
                  if(is_array($volume_ids['ip_add']['privateipadd']) && count($volume_ids['ip_add']['privateipadd']) > 0)
                  {
                    $pri_ip = implode("<br>", $volume_ids['ip_add']['privateipadd']);
                  }
                  else
                  {
                    $pri_ip = "";
                  }

                  if(is_array($volume_ids['ip_add']['publicipadd']) && count($volume_ids['ip_add']['publicipadd']) > 0)
                  {
                    $pub_ip = implode("<br>", $volume_ids['ip_add']['publicipadd']);
                  }
                  else
                  {
                    $pub_ip = "";
                  }

                  $data[$server_id]['pri_ip_add']     = $pri_ip;
                  $data[$server_id]['pub_ip_add']     = $pub_ip;
                  $data[$server_id]['volume_ids']     = json_encode($volume_ids['v_ids']);
                  $data[$server_id]['instance_type']  = $volume_ids['instance_type'];

                  foreach($volume_ids['vol_size'] as $row)
                  {
                    $vol_size[] = $row;
                  }

                  if(is_array($vol_size) && count($vol_size) > 0)
                  {
                    $size = implode("GB, ", $vol_size);
                    $size = $size."GB";
                  }
                  else
                  {
                    $size = "";
                  }

                  $data[$server_id]['volume_size']    = $size;
                }

                $s_hrs           = strtotime("00:00:00");
                $s_date          = strtotime("-1 day", $s_hrs);
                $data[$server_id]['fetched_date'] = date("Y-m-d",$s_date);

                if($ec_server_details['server_name'] != "")
                {
                  $ser_name = explode(".",$val[$instance]['name']);
                  
                  
                  $data[$server_id]['cluster'] = str_replace($ser_name[0].".","",$val[$instance]['name']);
                }

                $ram = $this->server_ssh($server_id);

                if($ram != "")
                {
                  $data[$server_id]['RAM'] = $ram;
                }
                else
                {
                  $data[$server_id]['RAM'] = "";
                }

                //------------------------EC DATA-------------------------------------------------------------

                if(is_array($ec_server_details) && count($ec_server_details) > 0)
                {
                  $ec_pri_ip = implode("<br>", $ec_server_details['ec_pri_ipadd']);
                  $ec_pub_ip = implode("<br>", $ec_server_details['ec_pub_ipadd']);

                  $data[$server_id]['server_name']      = $ec_server_details['server_name'];
                  $data[$server_id]['ec_cluster']       = $ec_server_details['ec_cluster'];
                  $data[$server_id]['ec_city']          = $ec_server_details['ec_city'];
                  $data[$server_id]['ec_region']        = array_search(trim($ec_server_details['ec_city']),$region_city_map);
                  $data[$server_id]['ec_provider']      = $ec_server_details['ec_provider'];
                  $data[$server_id]['ec_HDD']           = json_encode($ec_server_details['ec_HDD']);
                  $data[$server_id]['ec_CPU']           = json_encode($ec_server_details['ec_CPU']);
                  $data[$server_id]['ec_RAM']           = json_encode($ec_server_details['ec_RAM']);
                  $data[$server_id]['ec_pri_ipadd']     = $ec_pri_ip;
                  $data[$server_id]['ec_pub_ipadd']     = $ec_pub_ip;

                    $frm["instance_id"] = $data[$server_id]['instance_id'];
                            
                    //Cluster
                    $frm["cluster"] = $data[$server_id]['cluster'];
                    
                    //region
                    $frm["region"] = $region;
                    
                    //City
                    $result_city  = get_city();
                    $frm['city']  = array_search($data[$server_id]['city'],$result_city);
                    
                    //CPU
                    $result_CPU = $this->get_custom_list('CPU');
                    if(is_array($result_CPU) && count($result_CPU) > 0)
                    {
                      foreach($result_CPU as $res)
                      {
                        $instance_type[$res['id']] = $res['value']; 
                      }
                      
                      if(in_array($data[$server_id]['instance_type'],$instance_type))
                      {
                        $frm["instance_type"] = array_search($data[$server_id]['instance_type'],$instance_type);
                      }
                    }

                    //RAM
                    $result_RAM = $this->get_custom_list('RAM');
                    if(is_array($result_RAM) && count($result_RAM) > 0)
                    {
                      foreach($result_RAM as $res)
                      {
                        $aws_ram[$res['id']] = $res['value']; 
                      }
                      
                      if(in_array($data[$server_id]['RAM']." GB",$aws_ram))
                      {
                        $frm["ram"] = array_search($data[$server_id]['RAM']." GB",$aws_ram);
                      }
                      elseif(in_array($data[$server_id]['RAM']."GB",$aws_ram))
                      {
                        $frm["ram"] = array_search($data[$server_id]['RAM']."GB",$aws_ram);
                      }
                      elseif(in_array($data[$server_id]['RAM']."G",$aws_ram))
                      {
                        $frm["ram"] = array_search($data[$server_id]['RAM']."G",$aws_ram);
                      }
                      elseif(in_array($data[$server_id]['RAM']." G",$aws_ram))
                      {
                        $frm["ram"] = array_search($data[$server_id]['RAM']." G",$aws_ram);
                      }
                      else
                      {
                        $frm["ram"] = '458';
                      }
                    }

                    //HDD
                    $result_HDD = $this->get_custom_list('HDD');
                    if(is_array($result_HDD) && count($result_HDD) > 0)
                    {
                      foreach($result_HDD as $res)
                      {
                        $aws_hdd[$res['id']] = $res['value']; 
                      }
                      
                      if($data[$server_id]['volume_size'] != "")
                      {
                        $hdd = explode(", ",$data[$server_id]['volume_size']);

                        if(count($hdd) == 1)
                        {
                          $hdd[0] = str_replace('GB', '', $hdd[0]);

                          if(in_array($hdd[0]."GB",$aws_hdd) || in_array($hdd[0]." GB",$aws_hdd))
                          {
                            if(array_search($hdd[0]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD"] = array_search($hdd[0]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD"] = array_search($hdd[0]."GB",$aws_hdd);
                            }

                            $frm["HDD1"] = "0";
                            $frm["HDD2"] = "0";
                            $frm["HDD3"] = "0";
                            $frm["HDD4"] = "0";
                          }
                        }
                        elseif(count($hdd) == 2)
                        {
                          $hdd[0] = str_replace('GB', '', $hdd[0]);
                          $hdd[1] = str_replace('GB', '', $hdd[1]);

                          if(in_array($hdd[0]."GB",$aws_hdd) || in_array($hdd[0]." GB",$aws_hdd))
                          {
                            if(array_search($hdd[0]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD"] = array_search($hdd[0]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD"] = array_search($hdd[0]."GB",$aws_hdd);
                            }
                          }

                          if(in_array($hdd[1]."GB",$aws_hdd) || in_array($hdd[1]." GB",$aws_hdd))
                          {
                            if(array_search($hdd[1]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD1"] = array_search($hdd[1]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD1"] = array_search($hdd[1]."GB",$aws_hdd);
                            }
                          }

                          $frm["HDD2"] = "0";
                          $frm["HDD3"] = "0";
                          $frm["HDD4"] = "0";
                        }
                        elseif(count($hdd) == 3)
                        {
                          $hdd[0] = str_replace('GB', '', $hdd[0]);
                          $hdd[1] = str_replace('GB', '', $hdd[1]);
                          $hdd[2] = str_replace('GB', '', $hdd[2]);

                          if(in_array($hdd[0]."GB",$aws_hdd) || in_array($hdd[0]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[0]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD"] = array_search($hdd[0]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD"] = array_search($hdd[0]."GB",$aws_hdd);
                            }
                          }

                          if(in_array($hdd[1]."GB",$aws_hdd) || in_array($hdd[1]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[1]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD1"] = array_search($hdd[1]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD1"] = array_search($hdd[1]."GB",$aws_hdd);
                            }
                          }

                          if(in_array($hdd[2]."GB",$aws_hdd) || in_array($hdd[2]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[2]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD2"] = array_search($hdd[2]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD2"] = array_search($hdd[2]."GB",$aws_hdd);
                            }
                          }

                          $frm["HDD3"] = "0";
                          $frm["HDD4"] = "0";
                        }
                        elseif(count($hdd) == 4)
                        {
                          $hdd[0] = str_replace('GB', '', $hdd[0]);
                          $hdd[1] = str_replace('GB', '', $hdd[1]);
                          $hdd[2] = str_replace('GB', '', $hdd[2]);
                          $hdd[3] = str_replace('GB', '', $hdd[3]);

                          if(in_array($hdd[0]."GB",$aws_hdd) || in_array($hdd[0]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[0]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD"] = array_search($hdd[0]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD"] = array_search($hdd[0]."GB",$aws_hdd);
                            }
                          }

                          if(in_array($hdd[1]."GB",$aws_hdd) || in_array($hdd[1]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[1]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD1"] = array_search($hdd[1]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD1"] = array_search($hdd[1]."GB",$aws_hdd);
                            }
                          }

                          if(in_array($hdd[2]."GB",$aws_hdd) || in_array($hdd[2]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[2]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD2"] = array_search($hdd[2]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD2"] = array_search($hdd[2]."GB",$aws_hdd);
                            }
                          }

                          if(in_array($hdd[3]."GB",$aws_hdd) || in_array($hdd[3]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[3]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD3"] = array_search($hdd[3]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD3"] = array_search($hdd[3]."GB",$aws_hdd);
                            }
                          }

                          $frm["HDD4"] = "0";
                        }
                        elseif(count($hdd) == 5)
                        {
                          $hdd[0] = str_replace('GB', '', $hdd[0]);
                          $hdd[1] = str_replace('GB', '', $hdd[1]);
                          $hdd[2] = str_replace('GB', '', $hdd[2]);
                          $hdd[3] = str_replace('GB', '', $hdd[3]);
                          $hdd[4] = str_replace('GB', '', $hdd[4]);

                          if(in_array($hdd[0]."GB",$aws_hdd) || in_array($hdd[0]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[0]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD"] = array_search($hdd[0]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD"] = array_search($hdd[0]."GB",$aws_hdd);
                            }
                          }

                          if(in_array($hdd[1]."GB",$aws_hdd) || in_array($hdd[1]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[1]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD1"] = array_search($hdd[1]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD1"] = array_search($hdd[1]."GB",$aws_hdd);
                            }
                          }

                          if(in_array($hdd[2]."GB",$aws_hdd) || in_array($hdd[2]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[2]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD2"] = array_search($hdd[2]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD2"] = array_search($hdd[2]."GB",$aws_hdd);
                            }
                          }

                          if(in_array($hdd[3]."GB",$aws_hdd) || in_array($hdd[3]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[3]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD3"] = array_search($hdd[3]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD3"] = array_search($hdd[3]."GB",$aws_hdd);
                            }
                          }

                          if(in_array($hdd[4]."GB",$aws_hdd) || in_array($hdd[4]." GB",$aws_hdd) )
                          {
                            if(array_search($hdd[4]." GB",$aws_hdd) != "")
                            {
                              $frm["HDD4"] = array_search($hdd[4]." GB",$aws_hdd);
                            }
                            else
                            {
                              $frm["HDD4"] = array_search($hdd[4]."GB",$aws_hdd);
                            }
                          }
                        }
                      }  
                    }

                    $arr  = array("instance_id"   =>  $data[$server_id]['instance_id'],
                                  "region"        =>  $data[$server_id]['region'],
                                  "instance_type" =>  $frm["instance_type"],
                                );
                    $json = json_encode($arr);
                    
                    echo $modified_by   = "531"."<br>";
                    $modified_on        = date('Y-m-d H:i:s');
                    if($data[$server_id]['RAM'] != 'Not Connected')
                    {
                      echo $insert = "UPDATE servers 
                                SET cluster = '".$frm['cluster']."', city = '".$frm['city']."', HDD = '".$frm['HDD']."', HDD1= '".$frm['HDD1']."', HDD2= '".$frm['HDD2']."', HDD3= '".$frm['HDD3']."', HDD4= '".$frm['HDD4']."', RAM= '".$frm['ram']."',amazon_data= '".$json."'
                                WHERE id= ".$server_id."";
                      echo "<br>";
                      //update_all_history($server_id,'servers','id',$modified_by,$modified_on);
                    }
                    else
                    {
                      echo $insert = "UPDATE servers 
                                SET cluster = '".$frm['cluster']."', city = '".$frm['city']."', HDD = '".$frm['HDD']."', HDD1= '".$frm['HDD1']."', HDD2= '".$frm['HDD2']."', HDD3= '".$frm['HDD3']."', HDD4= '".$frm['HDD4']."', amazon_data= '".$json."'
                                WHERE id= ".$server_id."";
                      echo "<br>";
                      //update_all_history($server_id,'servers','id',$modified_by,$modified_on);
                    }
                }

                //-------------------------------------------------------------------------------------
              }
            }//foreach ($account_details as $instance => $acc)
          }//if (count($account_details) > 0)
        }//foreach ($region_arr as $region)
      }//if (is_array($region_arr) && !empty($region_arr))
    }//foreach ($providers_list as $provider_details)
    
    $cron_array   = array(
                            'data'             =>  $data
                          );
    
    $data         = get_cron_data($cron_id, $cron_array, $cron_data, $testing);
    
    $subject  = "Amazon Service check for all our accounts";
    if((array_key_exists('data', $data) && is_array($data['data']) && count($data['data']) > 0))
    {
      $recepients   = array('TestEC' => 'testec@');
      
      $cron_array['cron_id']              = $cron_id;
      $cron_array['list_servers']         = $data['data'];
      
      $body1  = $this->load->view('email_tpl_header', "", true);
      $body1 .= $this->load->view('cron_amazon_ec_view',$cron_array,true);
      $body1 .= $this->load->view('email_tpl_footer', "", true);

      echo "<pre>";
      print_r($body1);
      echo "</pre>";
      exit();
      
      //$mail_status  = send_mail($subject, $body1, $recepients, array(), '', '', "ExaCare", "noreplyecnotifications@");
    }
  }
  
  function get_custom_list($name)
  {
    $query = "SELECT * FROM ".TBL_CUSTOMVALUES." WHERE `name` LIKE '".$name."' AND status = 1 ORDER BY `value`";
    $res = $this->db->query($query);
    $result = $res->result_array();
    return $result;
  }
  
  function ec_server($sid = '')
  {
    $fetchdata_ec = "SELECT s.id, s.server_name, s.cluster, s.CPU, s.CPU2, s.HDD, s.HDD1, s.HDD2, s.HDD3, s.HDD4, s.RAM, c.name, p.provider_name
                    FROM ".TBL_SERVERS." AS s
                    LEFT JOIN city AS c ON s.city = c.id
                    LEFT JOIN providers AS p ON s.provider_id = p.id
                    WHERE s.id = '".$sid."'";
    $data_ec      = $this->db->query($fetchdata_ec)->result_array();
    
    if(is_array($data_ec) && count($data_ec) > 0)
    {
      $customvalues = "SELECT id,name,value FROM customvalues
                      WHERE status = 1
                      AND (id = '".$data_ec[0]['CPU']."' OR id = '".$data_ec[0]['CPU2']."' OR id = '".$data_ec[0]['HDD']."' OR id = '".$data_ec[0]['HDD1']."' OR id = '".$data_ec[0]['HDD2']."' OR id = '".$data_ec[0]['HDD3']."' OR id = '".$data_ec[0]['HDD4']."' OR id = '".$data_ec[0]['RAM']."') ";
      $cust_val     = $this->db->query($customvalues)->result_array();
      
      $result['server_name']  = $data_ec[0]['server_name'];
      $result['ec_cluster']   = $data_ec[0]['cluster'];
      $result['ec_city']      = $data_ec[0]['name'];
      $result['ec_provider']  = $data_ec[0]['provider_name'];
      
      if(is_array($cust_val) && count($cust_val) > 0)
      {    
        foreach($cust_val as $val)
        {
          $cust[$val['name']][] = $val['value'];
        }

        $result['ec_HDD']       = $cust['HDD'];
        $result['ec_CPU']       = $cust['CPU'];
        $result['ec_RAM']       = $cust['RAM'];
      }
    }
    
    $ip_add       = "SELECT ip_address, internal_ip_status FROM ip_addresses
                    WHERE server_id  = '".$sid."'
                    AND status = 'y'";
    $ip_val       = $this->db->query($ip_add)->result_array();

    if(is_array($ip_val) && count($ip_val) > 0)
    {
      foreach($ip_val as $val)
      {
        if($val['internal_ip_status'] == 1)
        {
          $pri_ips[]  = $val['ip_address'];
        }
        else
        {
          $pub_ips[]  = $val['ip_address'];
        }
      }
      
      $result['ec_pri_ipadd']     = $pri_ips;
      $result['ec_pub_ipadd']     = $pub_ips;
    }

    return $result;
  }
  
  function server_ssh($sid = '')
  {
    $fetchdata_query  = "SELECT s.id,s.server_name,s.server_username,s.server_password, ips.ip_address
                        FROM ".TBL_SERVERS." AS s
                        LEFT JOIN ".TBL_IP_ADDRESS." AS ips ON s.id = ips.server_id
                        WHERE s.id = '".$sid."'
                        AND ips.main_ip = 'y'
                        AND ips.status = 'y'";
    $fetchdata_query  = $this->db->query($fetchdata_query)->result_array();
    
    if (is_array($fetchdata_query) && count($fetchdata_query) > 0) 
    {
      foreach ($fetchdata_query as $key => $val)
      {
        $ip_address   = $val["ip_address"];
        $server_id    = $val["id"];
        $server_name  = $val["server_name"];

        $ip = $ip_address;
        $server_username = aes_decrypt($val['server_username']);
        $server_password = transform($val["server_password"], KEY);

        $this->ssh->server_name = $server_name;
        $this->ssh->ip          = $ip;
        $this->ssh->username    = $server_username;
        $this->ssh->password    = $server_password;
        //$this->ssh->cmd         = "grep MemTotal /proc/meminfo";
        $this->ssh->cmd         = "free -g | grep Mem | awk '{print $2}'";
        
        $stream1  = $this->ssh->connect();
        $pos      = strpos($stream1, "ERROR");

        if ($pos === false && $stream1 != "") 
        {  
          $value  = trim($stream1);
        }
        else 
        {
          $value  = "Not Connected";
        }
      }
    }
        
    return $value;    
  }
  
  function server_details($instance_id)
  {
    $sql = "Select server_id,id FROM ".TBL_AWSECLIST." WHERE instance_id = '".$instance_id."'";
    $server_data = $this->db->query($sql)->result_array();
    if (count($server_data) > 0)
    {
      return $server_data[0];
    }
    else
    {
      $server_data = array('server_id' => '','id' => '');
      return $server_data;
    }
  }//function server_details($instance_id)

  function describe_ec2_instances($instance_id)
  {
    $args['InstanceIds']  = array($instance_id);
    $volume_ids           = $this->myaws->describe_instances($args);

    if(is_array($volume_ids) && count($volume_ids) > 0)
    {
      $vol_ids        = $volume_ids[0]['Instances'][0]['BlockDeviceMappings'];
      
      if(is_array($volume_ids[0]['Instances'][0]['NetworkInterfaces']) && count($volume_ids[0]['Instances'][0]['NetworkInterfaces']) > 0)
      {
        $ip_values      = $volume_ids[0]['Instances'][0]['NetworkInterfaces'][0]['PrivateIpAddresses'];
      }
      else
      {
        $ip_values[0]['PrivateIpAddress']          = $volume_ids[0]['Instances'][0]['PrivateIpAddress'];
        $ip_values[0]['Association']['PublicIp']   = $volume_ids[0]['Instances'][0]['PublicIpAddress'];
      }

      $instance_type  = $volume_ids[0]['Instances'][0]['InstanceType'];
    }
    
    $value['instance_type'] = $instance_type;
    
    if(is_array($ip_values) && count($ip_values) > 0)
    {
      foreach($ip_values as $res)
      {
        $value['ip_add']['privateipadd'][] = $res['PrivateIpAddress'];
        $value['ip_add']['publicipadd'][]  = $res['Association']['PublicIp'];
      }
    }

    if(is_array($vol_ids) && count($vol_ids) > 0)
    {
      foreach ($vol_ids as $device)
      {
        $value['v_ids'][]     =  $device['Ebs']['VolumeId'];
      }
    
      $args['VolumeIds']  = $value['v_ids'];
    }
    
    $size               = $this->myaws->describe_volumes($args);
    
    if(is_array($size) && count($size) > 0)
    {
      foreach($size as $vol)
      {
        $value['vol_size'][$vol['VolumeId']] = $vol['Size'];
      }
    }

    return $value;
  }//function describe_ec2_instances($instance_id)

  function form_argument($namespace, $metric_code,$dimension)
  {
    $s_hrs          = strtotime("00:00:00");
    $e_hrs          = strtotime("23:59:59");
    $s_date         = strtotime("-1 day", $s_hrs);
    $e_date         = strtotime("-1 day", $e_hrs);
    
    $args = array(
            'Namespace' => $namespace,
            'MetricName' => $metric_code,
            'StartTime' => $s_date,
            'EndTime' => $e_date,
            'Period' => 1800,
            'Dimensions' => array($dimension)
            );
    
    switch ($metric_code) 
    {
      case 'CPUUtilization':
          $args['Unit'] =  'Percent';
          $args['Statistics'] = array('Average', 'Minimum','Maximum');
          break;
      case 'NetworkIn':
          $args['Statistics'] = array('Sum', 'Average');
          break;
      case 'NetworkOut':
          $args['Statistics'] = array('Sum', 'Average');
          break;
      case 'VolumeReadBytes':
          $args['Statistics'] = array('Sum', 'Average');
          break;
      case 'VolumeReadOps':
          $args['Statistics'] = array('Sum', 'Average');
          break;
      case 'VolumeWriteBytes':
          $args['Statistics'] = array('Sum', 'Average');
          break;
      case 'VolumeWriteOps':
          $args['Statistics'] = array('Sum', 'Average');
          break;
    }
    return $args;
  }//function form_argument($namespace, $metric_code,$dimension)
  
  function ec_details($access_key, $secret_key, $region, $provider)
  {
    $insert_array   = array();
    $aws_connection = $this->myaws->initialize($access_key, $secret_key, $region);
//    $list = $this->myaws->list_metrics();
//    echo '<pre>';
//    print_r($list);
//    echo '</pre>';
//    exit();
    
    try
    {
      $ec_list = $this->myaws->ec2_list();

      if (count($ec_list) > 0) 
      {
        foreach ($ec_list as $ect_details) 
        {
          if (array_key_exists('Instances', $ect_details) && count($ect_details['Instances']) > 0) 
          {
            foreach ($ect_details['Instances'] as $instances) 
            {
              $key = $instances['InstanceId'];
              
              if (array_key_exists('Tags', $instances) && count($instances['Tags']) > 0) 
              {
                foreach ($instances['Tags'] as $tag) 
                {
                  if ($tag['Key'] == 'Name') 
                  {
                    $name = $tag['Value'];
                  }
                }
              }
              $insert_array[$key]['name'] = $name;                      
              if (array_key_exists('InstanceId',$instances)) 
              {
                $insert_array[$key]['instance_id'] = $instances['InstanceId'];
              } 
              else 
              {
                $insert_array[$key]['instance_id'] = '';
              }
            }
          }
        }
      }
    }
    catch (Exception $e) 
    {
      $not_connected['not_connected'][$provider][] = $region;
    }
    return $insert_array;
  }
}//function ec_details($access_key, $secret_key, $region, $provider)

/* End of file cron_cloudwatch.php */
/* Location: ./application/modules/cron/controller/cron_cloudwatch.php */