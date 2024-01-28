<?php

/*
	Tesla suncharger PHP
	class file
	v.0.0.2
*/

class chargeTesla{

	function __construct($Tclass, $car_name, $lat_home, $long_home, $min_night_charge=30, $max_day_battery_level=90, $meter_threshold=250, $sunrise_hour=7, $sunset_hour=20){

		$this->Tclass = $Tclass;
		$this->car_name = $car_name;
		$this->lat_home = $lat_home;
		$this->long_home = $long_home;
		$this->min_night_charge = $min_night_charge;
		$this->max_day_battery_level = $max_day_battery_level;
		$this->meter_threshold = $meter_threshold;
		$this->sunrise_hour = $sunrise_hour;
		$this->sunset_hour = $sunset_hour;

		require_once $Tclass;
		$this->Tesla = new Tesla;

		$this->get_info();
	}

	function get_info(){

		$success = 0;
		$vehicle_id = $this->Tesla->select_vehicle_by_name($this->car_name);

		if(!is_numeric($vehicle_id))
			exit("No vehicle found");

		do{
			$wake_up = $this->Tesla->API("WAKE_UP");

			if($wake_up['response']['id'] == $this->Tesla->vehicleId)
				$success = 1;
			else
				sleep(3);
		} while ($success < 1);

		do{
			$this->status = $this->Tesla->API("VEHICLE_DATA");

			if($this->status['response']['id'] == $this->Tesla->vehicleId)
				$success = 2;
			else
				sleep(3);
		} while ($success < 2);

		$lat_raw = $this->status['response']['drive_state']['latitude'];
		$long_raw = $this->status['response']['drive_state']['longitude'];
		$plug_status = $this->status['response']['charge_state']['charge_port_door_open'];

		if((($lat_raw >= ($this->lat_home - 0.0002)) && ($lat_raw <= ($this->lat_home + 0.0002))) && (($long_raw >= ($this->long_home - 0.0002)) && ($long_raw <= ($this->long_home + 0.0002)))){
			$this->at_home = "At Home";
		}else{
			$this->at_home = "Not At Home";
		}
		if($plug_status){
			$this->tpi = "Plugged In";
		}else{
			$this->tpi = "Not Plugged In";
		}

		$this->charging_status(0);

		return array("Location" => $this->at_home, "State" => $this->tpi);
	}

	function charging_status($in=true){

		if($this->status['response']['charge_state']['charging_state'] == "Charging"){
			$this->currently_charging = "Charging";
			$current_amps = $this->status['response']['charge_state']['charge_amps'];
		}else{
			$this->currently_charging = "Not Charging";
			$current_amps = 0;
		}

		$charge_limit = $this->status['response']['charge_state']['charge_limit_soc'];
		$battery_level = $this->status['response']['charge_state']['battery_level'];

		if($in)
			return array("Mode" => $this->currently_charging, "Amps" => $current_amps, "Limit" => $charge_limit, "Battery" => $battery_level);
	}

	function start_charging(){
		$success = 0;
		do{
			$start_charging = $this->Tesla->API("START_CHARGE");
			if($start_charging['response']['result'] == 1 || $start_charging['response']['reason'] == "is_charging")
				$success = 1;
			else
				sleep(3);
		} while ($success < 1);

		return true;
	}

	function stop_charging(){
		$success = 0;
		do{
			$stop_charging = $this->Tesla->API("STOP_CHARGE");
			if($stop_charging['response']['result'] == 1 || $stop_charging['response']['reason'] == "not_charging")
				$success = 1;
			else
				sleep(3);
		} while ($success < 1);

		return true;
	}

	function set_charging_rate($amps){

		if(!is_numeric($amps))
			$amps = 16;

		if($amps < 0)
			$amps = 0;

		if($amps > 40)
			$amps = 40;

		$success = 0;
		do{
			$set_charging_rate = $this->Tesla->API("CHARGING_AMPS", array("charging_amps" => $amps));
			if($set_charging_rate['response']['result'] == 1)
				$success = 1;
			else
				sleep(3);
		} while ($success < 1);

		return true;
	}

	function cylcic_check($free_energy, $type=0){

		$mul = 1;
		if($type == 0){
			$mul = -1;
		}

		$now_hour = date("H", strtotime("now"));
		if($now_hour < $this->sunrise_hour || $now_hour > $this->sunset_hour){
			if($this->at_home == "At Home" && $this->tpi == "Plugged In"){
				if($this->status['response']['charge_state']['battery_level'] < $this->min_night_charge){
					if($this->currently_charging == "Not Charging"){
						$this->start_charging();
						$this->currently_charging = "Charging";
					}
					$new_amps = 32;	
				}else{
					if($this->currently_charging == "Charging"){
						$this->stop_charging();
						$this->currently_charging = "Not Charging";
					}
					$new_amps = 0;	
				}
				$this->set_charging_rate($new_amps);
				$active_limit = $this->min_night_charge;
			}
		}else{
			if(abs($free_energy) < $this->meter_threshold){
				$incremental_amps = 0;
			}else{
				$incremental_amps = round($free_energy / 230)*$mul;
			}
			$new_amps = $this->status['response']['charge_state']['charge_amps'] + $incremental_amps;
			if($this->at_home == "At Home" && $this->tpi == "Plugged In"){
				if($this->status['response']['charge_state']['battery_level'] < $this->max_day_battery_level){
					if($new_amps >= 5 && $this->currently_charging == "Not Charging"){
						$this->start_charging();
						$this->currently_charging = "Charging";
						$this->set_charging_rate($new_amps);
					}
					if($incremental_amps != 0 && $this->currently_charging == "Charging"){
						$this->set_charging_rate($new_amps);
					}
					if($this->status['response']['charge_state']['charge_amps'] <= 5 && $this->currently_charging == "Charging" && $incremental_amps >= -2){
						$this->stop_charging();
						$this->currently_charging = "Not Charging";
						$this->set_charging_rate(0);
					}
				}
			}
			$active_limit = $this->max_day_battery_level;
		}

		return array("Location" => $this->at_home, "State" => $this->tpi, "Mode" => $this->currently_charging, "Limit" => $active_limit, "Battery" => $this->status['response']['charge_state']['battery_level'], "Amps" => $new_amps, "Added amps" => $incremental_amps);

	}

}

?>
