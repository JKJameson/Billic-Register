<?php
class Register {
	public $settings = array(
		'name' => 'Register',
		'description' => 'Allows guests to register an account.',
	);
	private $isAddressRequired = true;
	function user_area() {
		global $billic, $db;
		if (!empty($billic->user)) {
			$billic->redirect('/');
		}
		if (get_config('register_skip_address')==='1')
			$this->isAddressRequired = false;
		$billic->module('FormBuilder');
		$license_data = $billic->get_license_data();
		if ($license_data['desc']!='Unlimited') {
			$lic_count = $db->q('SELECT COUNT(*) FROM `users`');
			if ($lic_count[0]['COUNT(*)'] >= $license_data['users']) {
				err('Unable to accept new users due to capacity. Please contact support.');
			}
		}
		if (isset($_GET['Activate'])) {
			$billic->set_title('Activation');
			if (empty($_GET['ID'])) {
				$billic->error('ID can not be empty');
			} else {
				$u = $db->q('SELECT * FROM `users` WHERE `id` = ?', $_GET['ID']);
				$u = $u[0];
				if (empty($u)) {
					$billic->error('Your activation link has expired, please register again');
				} else {
					if (empty($u['activation'])) {
						$billic->error('Your account is already activated');
					} else if ($u['activation'] != $_GET['Activate']) {
						$billic->error('Activation code is invalid');
					} else {
						$db->q('UPDATE `users` SET `status` = \'active\', `activation` = \'\', `redirect` = \'\' WHERE `id` = ?', $u['id']);
						$_SESSION['userid'] = $u['id'];
						if (empty($u['redirect'])) {
							$billic->redirect('/');
						} else {
							$billic->redirect($u['redirect']);
						}
					}
				}
			}
			echo '<h1>Activate Account</h1>';
			$billic->show_errors();
			exit;
		}
		$billic->set_title('Register');
		if (isset($_POST['register'])) {
			$firstname = $_POST['firstname'] ?? '';
			$lastname = $_POST['lastname'] ?? '';
			$companyname = $_POST['companyname'] ?? '';
			$vatnumber = $_POST['vatnumber'] ?? '';
			$address1 = $_POST['address1'] ?? '';
			$address2 = $_POST['address2'] ?? '';
			$city = $_POST['city'] ?? '';
			$state = $_POST['state'] ?? '';
			$postcode = $_POST['postcode'] ?? '';
            $country = $_POST['country'] ?? '';
			$phonenumber = $_POST['phonenumber'] ?? '';
			$email = $_POST['email'] ?? '';
			$captcha = $_POST['captcha'] ?? '';			
			if (empty($firstname)) {
				$billic->error('First Name can not be empty', 'firstname');
			} else if (!ctype_alpha($firstname)) {
				$billic->error('First Name can only be alphabetic characters', 'firstname');
			}
			if (empty($lastname)) {
				$billic->error('Last Name can not be empty', 'lastname');
			} else if (!ctype_alpha($lastname) && !ctype_alpha(substr($lastname, 0, 1).preg_replace('~\-~', '', substr($lastname, 1, -1), 1).substr($lastname, -1))) { // Allow a single dash in the middle of a word
				$billic->error('Last Name can only be alphabetic characters', 'lastname');
			}
			if (empty($companyname) && !empty($vatnumber)) {
				$billic->error('To use a VAT Number, you need to enter a Company Name', 'companyname');
			} else if (!empty($vatnumber)) {
				if (get_config('billic_vatnumber') != '' && $vatnumber == get_config('billic_vatnumber')) {
					$billic->error('Your VAT Number is invalid. Leave VAT empty if you don\'t have a number or don\'t know what this is.', 'vatnumber');
				} else if (substr($vatnumber, 0, 2) != $country) {
					$billic->error('Your VAT Number does not match your country', 'vatnumber');
					$billic->error('Your VAT Number does not match your country', 'country');
				} else {
					$state_code = substr($vatnumber, 0, 2);
					$vat_number = substr($vatnumber, 2);
					if (!$this->check_vat($vat_number, $state_code)) {
						$billic->error('Your VAT Number is invalid. Leave VAT empty if you don\'t have a number or don\'t know what this is.', 'vatnumber');
					}
				}
			}
			if ($this->isAddressRequired) {
				if (empty($address1))
					$billic->error('Address 1 can not be empty', 'address1');
				if (empty($city))
					$billic->error('City can not be empty', 'city');
				if (empty($state))
					$billic->error('State / County can not be empty', 'state');
			}
			if (empty($country) || !array_key_exists($country, $billic->countries))
				$billic->error('Country is invalid', 'country');
			if (empty($phonenumber))
				$billic->error('Phone Number can not be empty', 'phonenumber');
			else if (!ctype_digit($phonenumber))
				$billic->error('Phone Number must only be numbers', 'phonenumber');
			if (empty($_POST['password'])) {
				$billic->error('Password can not be empty', 'password');
				$billic->error(NULL, 'password2');
			} else if ($_POST['password'] !== $_POST['password2']) {
				$billic->error('Passwords do not match', 'password');
				$billic->error(NULL, 'password2');
			}
			if (!isset($_SESSION['order_save']) && (!isset($_SESSION['captcha']) || strtolower($_SESSION['captcha']) !== strtolower($_POST['captcha']))) {
				unset($_SESSION['captcha']);
				$billic->error('Captcha code invalid, please try again', 'captcha');
			} else {
				if (!$billic->valid_email($email)) {
					$billic->error('Email is invalid', 'email');
				} else {
					$check = $db->q('SELECT `id` FROM `users` WHERE `email` = ?', $email);
					if (count($check) > 0)
						$billic->error('Email is already in use for another account. Login using the form above', 'email');
				}
			}
			if (!empty($billic->errors)) {
				if (empty($billic->errors['password'])) {
					$billic->error('Please re-enter passwords', 'password');
					$billic->error(NULL, 'password2');
				}
			}
			if (empty($billic->errors)) {
				if (!isset($_SESSION['order_save']))
					unset($_SESSION['captcha']);
				if (!$this->isAddressRequired) {
					$address1 = '';
					$address2 = '';
					$city = '';
					$state = '';
					$postcode = '';
				}
				$referral = $_COOKIE['a'] ?? NULL;
				$activation = $billic->rand_str(10);
				$salt = $billic->rand_str(5);
				$password = md5($salt . $_POST['password']) . ':' . $salt;
				$billic->userid = $db->insert('users', array(
					'firstname' => $firstname,
					'lastname' => $lastname,
					'companyname' => $companyname,
					'vatnumber' => $vatnumber,
					'email' => $email,
					'address1' => $address1,
					'address2' => $address2,
					'city' => $city,
					'state' => $state,
					'postcode' => $postcode,
					'country' => $country,
					'phonenumber' => $phonenumber,
					'password' => $password,
					'datecreated' => time() ,
					'registered_ip' => $_SERVER['REMOTE_ADDR'],
					'registered_host' => @gethostbyaddr($_SERVER['REMOTE_ADDR']) ,
					//'status' => 'activation',
					'status' => 'Active',
					'activation' => $activation,
					'redirect' => base64_decode(urldecode($_GET['Redirect'])),
					'referral' => $referral,
				));
				$link = 'http' . (get_config('billic_ssl') == 1 ? 's' : '') . '://' . get_config('billic_domain') . '/Register/Activate/' . $activation . '/ID/' . $billic->userid . '/';
				/*$billic->email($email, 'Activate your Account', 'Dear '.$firstname.' '.$lastname.',<br>
				Thank you for registering. Please click the link below to activate your account.<br>
				<br>
				<a href="'.$link.'">'.$link.'</a><br>
				<br>
				ServeByte');
				echo '<h1>Check your email</h1>';
				echo '<p>We\'ve sent you an email at <b>'.$email.'</b> - click the link inside to activate your account.</p><p>Can\'t find the email? Please check your spam folder.</p>';*/
				if (isset($_SESSION['order_save'])) {
					$save = json_decode($_SESSION['order_save'], true);
					$_SESSION['userid'] = $billic->userid;
					$db->insert('logs_login', array(
						'userid' => $billic->userid,
						'timestamp' => time() ,
						'ipaddress' => $_SERVER['REMOTE_ADDR'],
						'hostname' => gethostbyaddr($_SERVER['REMOTE_ADDR']) ,
					));
					$user_row = $db->q('SELECT * FROM `users` WHERE `id` = ?', $billic->userid);
					$user_row = $user_row[0];
					$billic->module('Maxmind');
					$billic->module('Fraudic');
					$billic->module_call_functions('after_login', array(
						$user_row,
						$_POST['password']
					));
					$billic->redirect($save['uri']);
				}
				echo '<h1>Registration Complete</h1>';
				echo '<p>Thank you for registering. You may now login using the form above.</p>';
				exit;
			}
		}
		$billic->show_errors();
		echo '<br><br><form method="POST">';
		echo '<table class="table table-striped" style="width:600px;margin:auto">';
		echo '<tr><th colspan="2">Register an Account</th></tr>';
		$this->register_form();
		echo '<tr><td colspan="2" align="center"><input type="submit" class="btn btn-primary" name="register" value="Continue &raquo;"></td></tr>';
		echo '</table>';
		echo '</form>';
	}
	function register_form() {
		global $billic, $db;
        $countryPOST = $_POST['country'] ?? get_config('register_default_country');
		echo '<tr><td' . $billic->highlight('firstname') . '>First Name:</td><td><input type="text" class="form-control" name="firstname" value="' . safePOST('firstname') . '"></td></tr>';
		echo '<tr><td' . $billic->highlight('lastname') . '>Last Name:</td><td><input type="text" class="form-control" name="lastname" value="' . safePOST('lastname') . '"></td></tr>';
		echo '<tr style="opacity:0.8"><td' . $billic->highlight('companyname') . '>Company Name:<br><sup><i>Optional</i></sup></td><td><input type="text" class="form-control" name="companyname" value="' . safePOST('companyname') . '"></td></tr>';
		echo '<tr style="opacity:0.8"><td' . $billic->highlight('vatnumber') . '>VAT Number:<br><sup><i>Optional</i></sup></td><td><input type="text" class="form-control" name="vatnumber" value="' . safePOST('vatnumber') . '"> (For EU Customers only)</td></tr>';
		if ($this->isAddressRequired) {
			echo '<tr><td' . $billic->highlight('address1') . '>Address 1:</td><td><input type="text" class="form-control" name="address1" value="' . safePOST('address1') . '"></td></tr>';
			echo '<tr style="opacity:0.8"><td' . $billic->highlight('address2') . '>Address 2:<br><sup><i>Optional</i></sup></td><td><input type="text" class="form-control" name="address2" value="' . safePOST('address2') . '"></td></tr>';
			echo '<tr><td' . $billic->highlight('city') . '>City:</td><td><input type="text" class="form-control" name="city" value="' . safePOST('city') . '"></td></tr>';
			echo '<tr><td' . $billic->highlight('state') . '>State / County:</td><td><input type="text" class="form-control" name="state" value="' . safePOST('state') . '"></td></tr>';
			echo '<tr style="opacity:0.8"><td' . $billic->highlight('postcode') . '>Postcode:<br><sup><i>Optional</i></sup></td><td><input type="text" class="form-control" name="postcode" value="' . safePOST('postcode') . '"></div></td></tr>';
		}
		echo '<tr><td' . $billic->highlight('country') . '>Country:</td><td><select class="form-control" name="country">';
		foreach ($billic->countries as $key => $country) {
			echo '<option value="' . $key . '"' . ($key == $countryPOST ? ' selected="1"' : '') . '>' . $country . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><td' . $billic->highlight('phonenumber') . '>Phone Number:</td><td><input type="text" class="form-control" name="phonenumber" maxlength="15" value="' . safePOST('phonenumber') . '"></td></tr>';
		echo '<tr><td' . $billic->highlight('email') . '>Email:</td><td><input type="text" class="form-control" name="email" value="' . safePOST('email') . '"></td></tr>';
		echo '<tr><td' . $billic->highlight('password') . '>Password:</td><td><input type="password" class="form-control" name="password" ></td></tr>';
		echo '<tr><td' . $billic->highlight('password2') . '>Password Again:</td><td><input type="password" class="form-control" name="password2"></td></tr>';
		if (!isset($_SESSION['order_save'])) {
			echo '<tr><td' . $billic->highlight('captcha') . ' colspan="2"><div style="float:left;padding-right:20px"><img src="/Captcha/' . time() . '" width="150" height="75" alt="CAPTCHA"></div><br>Enter the number you see<br><input type="text" class="form-control" name="captcha" size="6" style="text-align:center;width:150px" value="' . (empty($billic->errors['captcha']) ? safePOST('captcha') : '') . '"></td></tr>';
		}
	}
	function check_vat($vat_no, $state_code) {
		$states = array(
			27 => 'RO',
			1 => 'AT',
			2 => 'BE',
			3 => 'BG',
			4 => 'CY',
			5 => 'CZ',
			6 => 'DE',
			7 => 'DK',
			8 => 'EE',
			9 => 'EL',
			10 => 'ES',
			11 => 'FI',
			12 => 'FR',
			13 => 'GB',
			14 => 'HU',
			15 => 'IE',
			16 => 'IT',
			17 => 'LT',
			18 => 'LU',
			19 => 'LV',
			20 => 'MT',
			21 => 'NL',
			22 => 'PL',
			23 => 'PT',
			24 => 'SE',
			25 => 'SI',
			26 => 'SK'
		);
		$found = array_search($state_code, $states);
		if ($found == 0) {
			//echo "FOUND - $found";
			return (false);
		}
		define('POSTURL', 'http://ec.europa.eu/taxation_customs/vies/viesquer.do');
		define('POSTVARS', "ms=$state_code&iso=$state_code&vat=$vat_no");
		$curlObject = curl_init(POSTURL);
		curl_setopt($curlObject, CURLOPT_HEADER, 0);
		curl_setopt($curlObject, CURLOPT_POST, 1);
		curl_setopt($curlObject, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curlObject, CURLOPT_POSTFIELDS, POSTVARS);
		$response = curl_exec($curlObject);
		preg_match('/Yes, valid VAT number/i', $response, $matches);
		if (isset($matches[0])) {
			return (true);
		}
		curl_close($curlObject);
	}
	function settings($array) {
		global $billic, $db;
		if (empty($_POST['update'])) {
			echo '<form method="POST"><input type="hidden" name="billic_ajax_module" value="Register"><table class="table table-striped">';
			echo '<tr><th>Setting</th><th>Value</th></tr>';
			echo '<tr><td>Default Country</td><td><select class="form-control" name="register_default_country">';
			$current = get_config('register_default_country');
			foreach ($billic->countries as $key => $country) {
				echo '<option value="' . $key . '"' . ($key == $current ? ' selected="1"' : '') . '>' . $country . '</option>';
			}
			echo '</select></td></tr>';
			echo '<tr><td colspan="2"><input type="checkbox" name="register_skip_address" value="1"'.(get_config('register_skip_address')==='1'?' checked':'').'> Do not collect address on registration form?</td></tr>';
			echo '<tr><td colspan="2" align="center"><input type="submit" class="btn btn-primary" name="update" value="Update &raquo;"></td></tr>';
			echo '</table></form>';
		} else {
			if (empty($billic->errors)) {
				set_config('register_default_country', $_POST['register_default_country']);
				set_config('register_skip_address', $_POST['register_skip_address']);
				$billic->status = 'updated';
			}
		}
	}
}
