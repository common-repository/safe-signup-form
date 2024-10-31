<?php
/*
 Plugin Name: Safe Signup Form
 Plugin URI: http://www.henrywoodbury.com/safe-signup-form/
 Description: A signup form that uses WordPress Hashcash  
 functionality to block spam submissions.
 Author: Henry Woodbury
 Author URI: http://www.henrywoodbury.com/
 Version: 1.2

 Hashcash functions are based on Elliot Back's WordPress 
 Hashcash version 4.3: http://wordpress-plugins.feifei.us/hashcash/
 
 This program is free software: you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation, either version 3 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 */

/* Define Options */

/* Either save or get options */
function ddfs_option($save = false) {
	if ($save) {
		update_option('ddfs', $save);
		return $save;
	} else {
		$options = get_option('ddfs');
		if (!is_array($options)) $options = array();
		return $options;
	}
}

/* Install Safe Signup Form */
function ddfs_install () {
	global $user_email;
// Set default hashcash options
	$options = ddfs_option();
	$options['submissions-spam-n'] = $options['submissions-spam-n'] || 0;
	$options['submissions-n'] = $options['submissions-n'] || 0;
	$options['key'] = array();
	$options['key-date'] = 0;
	$options['refresh'] = 60 * 60 * 24 * 7;

// New v1.1 "css-compliance" option
	$options['xhtml-compliance'] = true;

// 'handling' options are 'cancel (to delete spam), 'forward' to forward all messages and 'flag' to flag spam messages and forward them anyway
	$options['handling'] = 'flag';
	$options['msg-spam-flag'] = '<p class="error success">' . __('This form requires javascript to work correctly. Your submission may be flagged as spam.', 'ddfs') . '</p>';
	$options['msg-spam-cancel'] = '<p class="error">' . __('This form requires javascript to work, but your browser has javascript disabled.', 'ddfs') . '</p>';

// Set default email options...
	get_currentuserinfo();
	$options['forward-email'] = __($user_email, 'ddfs');
	$options['forward-subject'] = __('Signup Form Submission', 'ddfs');
	$options['forward-spam'] = __('FLAGGED AS SPAM');
	$options['form-success'] = '<p class="success">' . __('Thanks for signing up.', 'ddfs') . '</p>';
	$options['form-intro'] = '<p class="intro">' . __('Please provide the information requested below. We will use your email only to communicate with you. We will not share it with anyone else for any reason.', 'ddfs') . '</p>';
	$options['form-ps'] = __('', 'ddfs');
	$options['msg-error-required'] = '<p class="error">' . __('Please fill in all required fields.', 'ddfs') . '</p>';
	$options['msg-error-email'] = '<p class="error">' . __('Please enter a valid email address.', 'ddfs') . '</p>';
	$options['msg-error-mismatch'] = '<p class="error">' . __('The email addresses you entered do not match.', 'ddfs') . '</p>';
	$options['msg-error-malicious'] = '<p class="error">' . __('Please do not enter text such as "cc:", "mime-version", "content-type", or "to:" that appear intended to manipulate the form.', 'ddfs') . '</p>';

// Custom styles for the form
	$options['form-css'] = 'div.ddf label { padding-right: 0.5em; }
p.intro { font-style: italic; }
p.error { color: #ff0000; }
p.success { font-weight: bold; }';

// Rules for form validation - server side
// 'r' means required
// 'o' means optional
// 'e' means value must be a valid email
// a duplicate key requires a duplicate entry (as in 'ddfs-repeat-email')
	$options['error-rules'] = array(
		'ddfs-name' => 'r',
		'ddfs-email' => 'e',
		'ddfs-repeat-email' => 'ddfs-email'
	);

// Labels and fields for form elements
	$options['forward-labels'] = array(
		'Name' => 'ddfs-name',
		'Email' => 'ddfs-email'
	);

// Set default form code 
	$options['form-defaults'] = ddfs_form_display();
	$options['form'] = ddfs_form_display();

// Update options
	ddfs_option($options);
	ddfs_refresh();
}

register_activation_hook(__FILE__, 'ddfs_install');

/* Update the key */
function ddfs_refresh(){
// Get current options or empty array
	$options = ddfs_option();

// Check if refresh time has elapsed since last key refresh
	if (time() - $options['key-date'] > $options['refresh']) {
		if (count($options['key']) >= 5) array_shift($options['key']);
		array_push($options['key'], rand(21474836, 2126008810));
		$options['key-date'] = time();
		ddfs_option($options);
	}
}

// Do a key refresh after each successful page load
add_action('shutdown', 'ddfs_refresh');

/* Calculate statistics */
function ddfs_get_spam_ratio($ham, $spam) {
	if ($spam + $ham == 0) $ratio = 0;
	else $ratio = round(100 * ($spam/($ham + $spam)), 2);
	return $ratio;
}

/* Write statistics message */
function ddfs_statistics($options){
	$signups_n = (int)$options['submissions-n'];
	$signups_spam_n = (int)$options['submissions-spam-n'];
	$signups_ratio = ddfs_get_spam_ratio( $signups_n, $signups_spam_n );
	$signups_total = $signups_n + $signups_spam_n;
	$msg = '<p>' . $signups_spam_n . __(' spam form submission attempts identified out of ', 'ddfs') . $signups_total . ' total. ' . $signups_ratio . __('% of your submissions are spam!', 'ddfs') . '</p>';
	return $msg;
}



/* Admin page */

/* Add options page */
function ddfs_add_admin_options() {
// Put the options page under "Plug-ins"
	add_submenu_page('options-general.php', __('Safe Signup Form', 'ddfs'), __('Safe Signup Form', 'ddfs'), 'manage_options', 'ddfs', 'ddfs_admin_options');
}

add_action('admin_menu', 'ddfs_add_admin_options');

/* Write options page */
function ddfs_admin_options() {
// Get current option values
	$options = ddfs_option();

// POST HANDLER
	if ($_POST['ddfs-submit']) {
		check_admin_referer('ddfs-options');
		if (function_exists('current_user_can') && !current_user_can('manage_options') )
			die(__('Current user not authorized to manage options', 'ddfs'));

		$options['refresh'] = strip_tags(stripslashes($_POST['ddfs-refresh']));
		$options['handling'] = strip_tags(stripslashes($_POST['ddfs-handling']));
		$options['msg-spam-flag'] = stripslashes($_POST['ddfs-msg-spam-flag']);
		$options['msg-spam-cancel'] = stripslashes($_POST['ddfs-msg-spam-cancel']);
		$options['xhtml-compliance'] = strip_tags(stripslashes($_POST['ddfs-xhtml-compliance']));

		$options['forward-email'] = strip_tags(stripslashes($_POST['ddfs-forward-email']));
		$options['forward-subject'] = strip_tags(stripslashes($_POST['ddfs-forward-subject']));
		$options['form-success'] = stripslashes($_POST['ddfs-form-success']);
		$options['form-intro'] = stripslashes($_POST['ddfs-form-intro']);
		$options['form-ps'] = stripslashes($_POST['ddfs-form-ps']);
		$options['msg-error-required'] = stripslashes($_POST['ddfs-msg-error-required']);
		$options['msg-error-email'] = stripslashes($_POST['ddfs-msg-error-email']);
		$options['msg-error-mismatch'] = stripslashes($_POST['ddfs-msg-error-mismatch']);
		$options['msg-error-malicious'] = stripslashes($_POST['ddfs-msg-error-malicious']);
		$options['form-css'] = stripslashes($_POST['ddfs-form-css']);

		ddfs_option($options);
	}


// Write options page
// Fix some styles
	echo '<style type="text/css" media="screen">';
	echo '#wpcontent select { font-size: 13px; padding: 1px 2px 1px 2px; line-height: 19px; border: 1px solid #7f9db9; height: 1.8em; vertical-align: 0; }';
	echo 'input { padding: 1px 0 0 3px; border: 1px solid #7f9db9; height: 1.7em; }';
	echo 'input[type="submit"] { height: 1.9em; padding-bottom: 0.25em; }';
	echo 'textarea { padding: 1px 0 0 3px;  border: 1px solid #7f9db9; width: 400px; height: 5em; }';
	echo 'label { display: block; float: left; width: 160px; padding-top: 4px; }';
	echo 'p.setting-description { clear: both; font-style: italic; color: #666666; margin: -0.8em 0 0 160px; }';
	echo '</style>';
// Write options page
	echo '<div class="wrap">';
	echo '<h2>' . __('Safe Signup Form', 'ddfs') . '</h2>';
	echo '<p>' . __('This signup form utilizes Elliot Back&rsquo;s WordPress Hashcash functionality (version 4.3) to block spam submissions. It works because submissions must match a hidden field written by obfuscated javascript.', 'ddfs') . '</p>';

	echo '<h3>' . __('Statistics', 'ddfs') . '</h3>';
	echo ddfs_statistics($options);

	echo '<h3>' . __('Hashcash Options', 'ddfs') . '</h3>';
	echo '<form method="POST" action="?page=' . $_GET[ 'page' ] . '&updated=true">';
	wp_nonce_field('ddfs-options');

// Handling option
	$handling = htmlspecialchars($options['handling'], ENT_QUOTES);
	echo '<p><label for="ddfs-handling">' . __('Handling:', 'ddfs') . '</label>';
	echo '<select id="ddfs-handling" name="ddfs-handling">';
	echo '<option value="flag"' . ($handling == 'flag' ? ' selected="selected"' : '') . '>' . __('Flag spam submissions', 'ddfs') . '</option>';
	echo '<option value="cancel"' . ($handling == 'cancel' ? ' selected="selected"' : '') . '>' . __('Delete spam submissions', 'ddfs') . '</option>';
	echo '<option value="forward"' .($handling == 'forward' ? ' selected="selected"' : '') . '>' . __('Forward without flagging', 'ddfs') . '</option>';
	echo '</select></p>';

// Spam message for "flagged"
	$msg_spam_flag = htmlspecialchars($options['msg-spam-flag'], ENT_QUOTES);
	echo '<p><label for="ddfs-msg-spam-flag">' . __('Error - spam flag:', 'ddfs') . '</label>
		<textarea name="ddfs-msg-spam-flag" id="ddfs-msg-spam-flag">' . $msg_spam_flag  . '</textarea></p>
		<p class="setting-description">' . __('Displayed if submission is flagged as spam, but permitted.', 'ddfs') . '</p>';

// Spam message for "canceled"
	$msg_spam_cancel = htmlspecialchars($options['msg-spam-cancel'], ENT_QUOTES);
	echo '<p><label for="ddfs-msg-spam-cancel">' . __('Error - spam cancel:', 'ddfs') . '</label>
		<textarea name="ddfs-msg-spam-cancel" id="ddfs-msg-spam-cancel">' . $msg_spam_cancel  . '</textarea></p>
		<p class="setting-description">' . __('Displayed if submission is flagged as spam and cancelled.', 'ddfs') . '</p>';

// CSS compliance
	$xhtml_compliance = htmlspecialchars($options['xhtml-compliance'], ENT_QUOTES);
	echo '<p><label for="ddfs-xhtml-comliance">' . __('XHTML compliant:', 'ddfs') . '</label>
		<input type="checkbox" name="ddfs-xhtml-compliance" id="ddfs-xhtml-compliance"' . ($xhtml_compliance ? 'checked="checked"' : '') . '" /></p>
		<p class="setting-description">' . __('Check to place javascript associated with this plugin into the header of every page. Uncheck to place javascript within the body of the page. The latter is not XHTML compliant, but keeps the javascript local to the page.', 'ddfs') .'</p>';

// Refresh interval
	$refresh = htmlspecialchars($options['refresh'], ENT_QUOTES);
	echo '<p><label for="ddfs-refresh">' . __('Key Expiry:', 'ddfs').'</label>
		<input style="width: 200px;" id="ddfs-refresh" name="ddfs-refresh" type="text" value="' . $refresh . '" /></p>
		<p class="setting-description">' . __('Default is one week, or 604800 seconds.', 'ddfs') . '</p>';

// Current key
	echo '<p>' . __('Your current key is ', 'ddfs') . '<strong>' . $options['key'][count($options['key']) - 1] . '</strong>.';
	if (count($options['key']) > 1) echo __(' Previously you had keys ', 'ddfs') . join(', ', array_reverse(array_slice($options['key'], 0, count($options['key']) - 1))).'.';
	echo '</p>';

	echo '<h3>' . __('Form Options', 'ddfs') . '</h3>';

// Forwarding 
	$forward_email = htmlspecialchars($options['forward-email'], ENT_QUOTES);
	echo '<p><label for="ddfs-forward-email">' . __('Forwarding email:', 'ddfs') . '</label>
		<input style="width: 400px;" id="ddfs-forward-email" name="ddfs-forward-email" type="text" value="' . $forward_email . '" /></p>
		<p class="setting-description">' . __('Email recipient of the form submission. Multiple recipients can be separated by a comma.', 'ddfs') . '</p>';

	$forward_subject = htmlspecialchars($options['forward-subject'], ENT_QUOTES);
	echo '<p><label for="ddfs-forward-subject">' . __('Forwarding subject:', 'ddfs') . '</label>
		<input style="width: 400px;" id="ddfs-forward-subject" name="ddfs-forward-subject" type="text" value="' . $forward_subject . '" /></p>
		<p class="setting-description">' . __('The subject of the email.', 'ddfs') . '</p>';

	$form_success = htmlspecialchars($options['form-success'], ENT_QUOTES);
	echo '<p><label for="ddfs-form-success">' . __('Success message:', 'ddfs') . '</label>
		<textarea name="ddfs-form-success" id="ddfs-form-success">' . $form_success . '</textarea></p>
		<p class="setting-description">Message displayed to user on successful submission.</p>';

	$form_intro = htmlspecialchars($options['form-intro'], ENT_QUOTES);
	echo '<p><label for="ddfs-form-intro">' . __('Introductory text:', 'ddfs') . '</label>
		<textarea name="ddfs-form-intro" id="ddfs-form-intro">' . $form_intro . '</textarea></p>
		<p class="setting-description">' . __('Text that introduces the form.', 'ddfs') . '</p>';

	$form_ps = htmlspecialchars($options['form-ps'], ENT_QUOTES);
	echo '<p><label for="ddfs-form-ps">' . __('Follow-up text:', 'ddfs') . '</label>
		<textarea name="ddfs-form-ps" id="ddfs-form-ps">' . $form_ps . '</textarea></p>
		<p class="setting-description">' . __('Text that follows the form.', 'ddfs') . '</p>';

	echo '<h3>' . __('Error Messages', 'ddfs') . '</h3>';

	$msg_error_required = htmlspecialchars($options['msg-error-required'], ENT_QUOTES);
	echo '<p><label for="ddfs-msg-error-required">' . __('Error - required fields:', 'ddfs') . '</label>
		<textarea name="ddfs-msg-error-required" id="ddfs-msg-error-email">' . $msg_error_required  . '</textarea></p>
		<p class="setting-description">' . __('Displayed if required fields are missing.', 'ddfs') . '</p>';

	$msg_error_email = htmlspecialchars($options['msg-error-email'], ENT_QUOTES);
	echo '<p><label for="ddfs-msg-error-email">' . __('Error - email syntax:', 'ddfs') . '</label>
		<textarea name="ddfs-msg-error-email" id="ddfs-msg-error-email">' . $msg_error_email  . '</textarea></p>
		<p class="setting-description">' . __('Error message displayed for improper email address.', 'ddfs') . '</p>';

	$msg_error_mismatch = htmlspecialchars($options['msg-error-mismatch'], ENT_QUOTES);
	echo '<p><label for="ddfs-msg-error-mismatch">' . __('Error - email mismatch:', 'ddfs') . '</label>
		<textarea name="ddfs-msg-error-mismatch" id="ddfs-msg-error-mismatch">' . $msg_error_mismatch  . '</textarea></p>
		<p class="setting-description">' . __('Error message displayed if email addresses don\'t match.', 'ddfs') . '</p>';

	$msg_error_malicious = htmlspecialchars($options['msg-error-malicious'], ENT_QUOTES);
	echo '<p><label for="ddfs-msg-error-malicious">' . __('Error - malicious entry:', 'ddfs') . '</label>
		<textarea name="ddfs-msg-error-malicious" id="ddfs-msg-error-malicious">' . $msg_error_malicious  . '</textarea></p>
		<p class="setting-description">' . __('Error message displayed if entry includes "mime-type," "cc:" or other attempts to manipulate the form.', 'ddfs') . '</p>';

	echo '<h3>' . __('Styles', 'ddfs') . '</h3>';

	$form_css = htmlspecialchars($options['form-css'], ENT_QUOTES);
	echo '<p><label for="ddfs-form-css">' . __('Custom styles:', 'ddfs') . '</label>
		<textarea name="ddfs-form-css" id="ddfs-form-css">' . $form_css  . '</textarea></p>
		<p class="setting-description">' . __('Code custom CSS classes for the form.', 'ddfs') . '</p>';

// Submit
	echo '<p style="padding-left: 160px;"><input type="hidden" id="ddfs-submit" name="ddfs-submit" value="1" />';
	echo '<input type="submit" id="ddfs-submit-override" name="ddfs-submit-override" value="' . __('Save Settings', 'ddfs') . '" /></p>';
	echo '</form>';

	echo '<p style="font-size: 0.8em;">&copy; ' . __('Copyright ', 'ddfs') . date('Y') . ' <a href="http://www.henrywoodbury.com">Henry Woodbury</a></p>';

	echo '</div>';
}

/* Get Haschcash js */
function ddfs_getjs() {
	$options = ddfs_option();
	$val = $options['key'][count($options['key']) - 1];
	$js = 'function ddfs_compute() {';

	switch(rand(0, 3)){
		/* Addition of n times of field value / n, + modulus:
		 Time guarantee:  100 iterations or less */
		case 0:
			$inc = rand($val / 100, $val - 1);
			$n = floor($val / $inc);
			$r = $val % $inc;
		
			$js .= "var ddfs_eax = $inc; ";
			for($i = 0; $i < $n - 1; $i++){
				$js .= "ddfs_eax += $inc; ";
			}
			
			$js .= "ddfs_eax += $r; ";
			$js .= 'return ddfs_eax; ';
			break;

			/* Conversion from binary:
		Time guarantee:  log(n) iterations or less */
		case 1:
			$binval = strrev(base_convert($val, 10, 2));
			$js .= "var ddfs_eax = \"$binval\"; ";
			$js .= 'var ddfs_ebx = 0; ';
			$js .= 'var ddfs_ecx = 0; ';
			$js .= 'while(ddfs_ecx < ddfs_eax.length){ ';
			$js .= 'if(ddfs_eax.charAt(ddfs_ecx) == "1") { ';
			$js .= 'ddfs_ebx += Math.pow(2, ddfs_ecx); ';
			$js .= '} ';
			$js .= 'ddfs_ecx++; ';
			$js .= '} ';
			$js .= 'return ddfs_ebx;';
			
		break;
		
		/* Multiplication of square roots:
		Time guarantee:  constant time */
		case 2:
			$sqrt = floor(sqrt($val));
			$r = $val - ($sqrt * $sqrt);
			$js .= "return $sqrt * $sqrt + $r; ";
		break;
		
		/* Sum of random numbers to the final value:
		Time guarantee:  log(n) expected value */
		case 3:
			$js .= 'return ';
	
			$i = 0;
			while($val > 0){
				if($i++ > 0)
					$js .= '+';
				
				$temp = rand(1, $val);
				$val -= $temp;
				$js .= $temp;
			}
	
			$js .= ';';
		break;
	}
		
	$js .= '} ddfs_compute();';
	
// pack bytes
	if( !function_exists( 'strToLongs' ) ) {
		function strToLongs($s) {
			$l = array();
// pad $s to some multiple of 4
			$s = preg_split('//', $s, -1, PREG_SPLIT_NO_EMPTY);
			while(count($s) % 4 != 0){
				$s [] = ' ';
			}
			for ($i = 0; $i < ceil(count($s)/4); $i++) {
				$l[$i] = ord($s[$i*4]) + (ord($s[$i*4+1]) << 8) + (ord($s[$i*4+2]) << 16) + (ord($s[$i*4+3]) << 24);
		   	}
		return $l;
		}
	}

	// xor all the bytes with a random key
	$key = rand(21474836, 2126008810);
	$js = strToLongs($js);

	for($i = 0; $i < count($js); $i++){
		$js[$i] = $js[$i] ^ $key;
	}

// libs function encapsulation
	$libs .= "function ddfs_hc() {\n";

// Write bytes to javascript, xor with key
	$libs .= "\tvar ddfs_data = [" . join(',', $js) . "]; \n";

// Do the xor with key
	$libs .= "\n\tfor (var i = 0; i < ddfs_data.length; i++) {\n";
	$libs .= "\t\tddfs_data[i]=ddfs_data[i]^$key;\n";
	$libs .= "\t}\n";

// Convert bytes back to string
	$libs .= "\n\tvar a = new Array(ddfs_data.length); \n";
	$libs .= "\tfor (var i=0; i < ddfs_data.length; i++) { \n";
	$libs .= "\t\ta[i] = String.fromCharCode(ddfs_data[i] & 0xFF, ddfs_data[i]>>>8 & 0xFF, ";
	$libs .= "ddfs_data[i]>>>16 & 0xFF, ddfs_data[i]>>>24 & 0xFF);\n";
	$libs .= "\t}\n";
	$libs .= "\n\treturn eval(a.join('')); \n";
	$libs .= "}\n";
	if ($options['xhtml-compliance']) {
		$libs .= "ddfsLoadEvent(function() { var el = document.getElementById('f-ddfs-hc'); if (el) { el.value = ddfs_hc(); }});\n";
	} else {
		$libs .= "var el = document.getElementById('f-ddfs-hc');\n";
		$libs .= "if (el) el.value = ddfs_hc();\n";
	}
	return $libs;
}

/* Read form */
function ddfs_form_display() {
	$form = array(
// Error message markup
		'error-msg' => '',
// Rules for form validation - client side
		'error-field' => '<input id="f-ddfs-rules" name="ddfs-rules" type="hidden" value="f-ddfs-name:r f-ddfs-email:e f-ddfs-repeat-email:d:f-ddfs-email" />', 
// Default form markup
		'ddfs-name' => '<p><label for="f-ddfs-name" class="required">' . __('First Name', 'ddfs') . '</label><input type="text" name="ddfs-name" id="f-ddfs-name" maxlength="50" tabindex="10" value="' . htmlentities($_POST['ddfs-name']) . '" class="f" /></p>',
		'ddfs-email' => '<p><label for="f-ddfs-email" class="required">' . __('Email Address', 'ddfs') . '</label><input type="text" name="ddfs-email" id="f-ddfs-email" maxlength="50" tabindex="15" value="' . htmlentities($_POST['ddfs-email']) . '" class="f" /></p>',
		'ddfs-repeat-email' => '<p><label for="f-ddfs-repeat-email" class="required">' . __('Repeat Email Address', 'ddfs') . '</label><input type="text" name="ddfs-repeat-email" id="f-ddfs-repeat-email" maxlength="50" tabindex="16" value="' . htmlentities($_POST['ddfs-repeat-email']) . '" class="f" /></p>'
	);
	return $form;	
}

/* Check Hashcash value */
function ddfs_hashcash() {
	$options = ddfs_option();
	$result = 'forward';
	$spam = false;
// Check the wphc values against the last five keys
	$spam = !in_array($_POST["ddfs-hc"], $options['key']);
	if ($spam){
		$options['submissions-spam-n'] = ((int) $options['submissions-spam-n']) + 1;
		ddfs_option($options);
		$result = stripslashes($options['handling']);
	} else {
		$options['submissions-n'] = ((int) $options['submissions-n']) + 1;
		ddfs_option($options);
	}
	return $result;
}


/* Check for malicious entries */
function ddfs_check_malicious($v) {
	if (empty($v)) return false;
	$mal = array("mime-version", "content-type", "cc:", "to:");
	foreach ($mal as $m) {
		if (strpos(strtolower($v), strtolower($m)) !== false) return true;
	}
	return false;
}

/* Check for errors */
function ddfs_check_input() {
	$options = ddfs_option();
	$form = ddfs_form_display();
	$fields = $options['error-rules'];
	$errorR = false; // Empty required field error
	$errorD = false; // Duplicate mismatch error
	$errorE = false; // Email syntax error
	$errorM = false; // Malicious entry error (for all text or textarea fields)
	$errorG = false; // Generic error
	foreach ($fields as $k => $v) {
		$kv = stripslashes(trim($_POST[$k]));
		switch ($v) {
			case "r": // required fields
				if (ddfs_check_malicious($kv)) {
					$errorM = $k;
				} elseif (empty($_POST[$k])) {
					$errorR = $k;
				}
				break;
			case "e": // email field
				if (ddfs_check_malicious($kv)) {
					$errorM = $k;
				} elseif (empty($_POST[$k])) {
					$errorR = $k;
				} elseif (!is_email($_POST[$k])) {
					$errorE = $k;
				}
			break;
			case "o": // optional fields
				if (ddfs_check_malicious($kv)) {
					$errorM = $k;
				}
			break;
			default:
 // duplicate emails
				if (array_key_exists($v, $fields)) {
					$vv = stripslashes(trim($_POST[$v]));
					if (ddfs_check_malicious($kv)) {
						$errorM = $k;
					} elseif ($vv != $kv ) {
						$errorD = $k;
					}
					if (empty($_POST[$k])) {
						$errorG = $k;
					}
				}
			break;
		}
		if ($errorR == $k || $errorM == $k || $errorD == $k || $errorE == $k || $errorG == $k) {
			$form[$k] = preg_replace('/<p>/', '<p class="error">', $form[$k]);
		}
	}
	if ($errorR || $errorD || $errorE || $errorM || $errorG == $k) {
		if ($errorM) $form['error-msg'] .= stripslashes($options['msg-error-malicious']);
		elseif ($errorR) $form['error-msg'] .= stripslashes($options['msg-error-required']);
		elseif ($errorE) $form['error-msg'] .= stripslashes($options['msg-error-email']);
		elseif ($errorD) $form['error-msg'] .= stripslashes($options['msg-error-mismatch']);
		$options['form'] = $form;
		ddfs_option($options);
		return false;
	}
	return true;
}

// Call the form
function ddfs_callback() {
	if (isset($_POST['ddfs-flag'])) {
		$success = ddfs_check_input();
		$hashcash = ddfs_hashcash();
		$options = ddfs_option();
		$form = $options['form'];
	} else {
		$options = ddfs_option();
		$form = $options['form-defaults'];
	}
	$ddfs = '';
// Write CSS whether success or failure
	if (!$options['xhtml-compliance']) {
		$ddfs .= "<style type=\"text/css\" media=\"screen\">\n";
		$ddfs .= $options['form-css'] . "\n";
		$ddfs .= "</style>\n";
	}
// Forward email and write output	
	if ($success && $hashcash != 'cancel') {
		$mailto = $options['forward-email'];
		$subject = $options['forward-subject'];
		if ($hashcash == 'flag') $subject .= " - " . $options['forward-spam'];

		$headers = "MIME-Version: 1.0\r\n";
		$headers .= "From:" . get_bloginfo('name') .  " -- Submission\r\n";
		$headers .= "Content-Type: text/plain; charset=\"" . get_settings('blog_charset') . "\"\r\n";
		
		$labels = $options['forward-labels'];
		$msg = "";
		foreach ($labels as $k => $v) {
			$vv = stripslashes(trim($_POST[$v]));
			$msg .= $k . ": " . $vv . "\n";
		}
		$msg .= "IP: " . ddfs_getip();

		wp_mail($mailto, $subject, $msg, $headers);
// Write output - success
		if ($hashcash == 'flag') $ddfs .= $options['msg-spam-flag'];
		else $ddfs .= stripslashes($options['form-success']);
	} else {
// Write output - form
		if ($hashcash == 'cancel') $form['error-msg'] =  stripslashes($options['msg-spam-cancel']);
		$intro = stripslashes($options['form-intro']);
		$ps = stripslashes($options['form-ps']);
		if (strlen($intro) > 0) $ddfs .= $intro;	
//		$ddfs .= "<form action=\"http:\\\\" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"] . "\" id=\"f-ddfs\" method=\"post\">\n";
//		  OR
		$ddfs .= "<form action=\"\" id=\"f-ddfs\" method=\"post\">\n";
		$ddfs .= "<div class=\"ddf\">\n";
		$ddfs .= "<input type=\"hidden\" id=\"f-ddfs-hc\" name=\"ddfs-hc\" value=\"\" />\n";
		$ddfs .= "<input type=\"hidden\" id=\"f-ddfs-flag\" name=\"ddfs-flag\" value=\"ready\" />\n";
		foreach ($form as $v) {
			$ddfs .= $v;
		}
		$ddfs .= "<p class=\"push ddf-right\"><input class=\"fs\" type=\"submit\" value=\"" . __('Send', 'ddfs') . "\" /></p>\n";
		$ddfs .= "</div>\n";
		$ddfs .= "</form>\n";
		if (strlen($ps) > 0) $ddfs .= $ps;	
		if (!$options['xhtml-compliance']) {
// Write JS at end of form. This is not XHTML compliant but targets the JavaScript
// to pages that have the form and is a commonplace for AJAX applications.
			$ddfs .= "<script type=\"text/javascript\"><!--\n";
			$ddfs .= ddfs_getjs() . "\n";
			$ddfs .= "//--></script>";
		}
	}
	return $ddfs;
}

/* Write CSS */
function ddfs_posthead_css() {
	$options = ddfs_option();
	if (!$options['xhtml-compliance']) return;
	echo "<style type=\"text/css\" media=\"screen\">\n";
	echo $options['form-css'] . "\n";
	echo "</style>\n";
}

/* Place JavaScript in Header */
function ddfs_posthead_js() {
	$options = ddfs_option();
	if (!$options['xhtml-compliance']) return;
	echo "<script type=\"text/javascript\"><!--\n";
	echo 'function ddfsLoadEvent(func) {
	var oldonload = window.onload;
	if (typeof window.onload != \'function\') {
		window.onload = func;
	} else {
		window.onload = function() {
			if (oldonload) oldonload();
			func();
		}
	}
}
';
// Hashcash JS
	echo  ddfs_getjs() . "\n";
	echo "//--></script>\n";
}

// Write JS to the header of every page
add_action('wp_head', 'ddfs_posthead_js');

// Write JS to the header of every page
add_action('wp_head', 'ddfs_posthead_css');

/* Call the form from a post */
add_shortcode('ddfs', 'ddfs_callback');


/* Or call the form directly */
function ddfs() {
	echo ddfs_callback();
}

// Return submitter's IP address
function ddfs_getip() {
	return (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : (isset($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : $_SERVER['REMOTE_ADDR']));
}

/* Validate the ddfs_value key */
function ddfs_check_hidden_tag($result = 'forward') {
	$options = ddfs_option();
	$spam = false;

// Check the ddfs values against the last five keys
	$spam = !in_array($_POST["ddfs_value"], $options['key']);

	if ($spam) {
// Add spam count and return handling result
		$options['submissions-spam-n'] = ((int) $options['submissions-spam-n']) + 1;
		ddfs_option($options);
		$result = $options['handling'];
	} else {
// Add no-spam count
		$options['submissions-n'] = ((int) $options['submissions-n']) + 1;
		ddfs_option($options);
	}
	
	return $result;
}

?>
