<?php
/*
Plugin Name: Kramer
Version: 0.7.7
Plugin URI: http://dev.wp-plugins.org/wiki/Kramer
Description: Implements Technorati inbound links to a post as Pingbacks. Also provides a template function to display general inbound links.
Author: Nik Cubrilovic and Mark Jaquith
Author URI: http://www.nik.com.au/
*/

/*  Copyright 2005  Nik Cubrilovic (email: nik@nik.com.au)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/


// Serve the GIF if it is requested
if ( strpos($_SERVER['REQUEST_URI'], '/kramer.php?kramer=gif-icon') !== false ) {
	$gif = <<<EOF
47494638396114000d00c41100ccf2db6da7bf11c25533cb6dc6dde64790aebad5e177adc398c1d299e5b6bbeece44cf7aaecedb85b6ca3485a666d89200be49ffffff00000000000000000000000000000000000000000000000000000000000000000000000000000000000021f90401000011002c0000000014000d0000056060248e64693e10f488400a094084340889aaa290c251e014a5db6ab08bf980c1d463e132fe4c919b0b32e8391c8186562b924e1fd7b0b83b25be0ed70363bd26a71211d7a371259c946e8882eeb0dbf0222e557c7e6e2b7970060c06508d8d21003b
EOF;

	header("Content-type: image/gif");
	header("Cache-Control: must-revalidate");
	$offset = 60 * 60 * 24 * 3; // last number is how many days it should be cached
	header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $offset) . ' GMT');
	echo pack("H" . strlen($gif), $gif);
	exit();
}


class Kramer {

	var $auto_ref_pingback;
	var $version = '0.7.7';
	var $api_key;
	var $cache_expiry;
	var $debug_output;
	var $current_tag;
	var $new_weblog;
	var $post_id;
	var $do_synch;
	var $force_synch;
	var $get_vars;
	// var $post_synch;
	var $post_synch_value;
	var $url;
	var $weblog_url;
	var $weblog_entry_url;
	var $weblog_name;
	var $weblog_date;
	var $weblog_content;
	var $parse_error;
	var $comments_type;
	var $ref;
	// var $pending_pingbacks;
	var $email_notification;
	var $technorati_balloon_class;

	function Kramer() {
		// load i18n translations
		load_plugin_textdomain('kramer');

		// initialize all the variables
		$this->api_key = get_option('kramer_apikey');
		$this->cache_expiry = get_option('kramer_cacheexpiry');
		$this->force_synch = $_GET['forcesynch'];
		$this->get_vars = get_defined_vars();
		$this->debug_output = array();
		$this->url = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		$this->comments_type = 'pingback';
		$this->email_notification = get_option('kramer_email_notification');
		$this->auto_ref_pingback = get_option('kramer_auto_ref_pingback');
		// $this->technorati_balloon_class = get_option('technorati_balloon_class');
		$this->ref = ( strlen($_SERVER['HTTP_REFERER']) > 9 ) ? $_SERVER['HTTP_REFERER'] : false;

		// do some empty tests (this happens if they haven't visited the options page)
		if ( !$this->email_notification ) $this->email_notification = "yes";
		if ( !$this->technorati_balloon_class ) $this->technorati_balloon_class = "technorati-balloon";
		if ( !$this->cache_expiry) $this->cache_expiry = 86400;
		if ( !$this->auto_ref_pingback ) $this->auto_ref_pingback = "yes";

	}


	function init() {

		add_action('comment_text', array(&$this, 'convert_ping_tags'));
		add_action('preprocess_comment', array(&$this, 'examine_incoming_pingback'));

		add_action('wp_footer', array(&$this, 'show_debug'));
		add_action('admin_menu', array(&$this, 'admin_menu'));

		global $wpdb;
		$this->post_id = url_to_postid($this->url);

		if (!$this->post_id) {
			$this->debug(sprintf(__('No post ID found for %s', 'kramer'), $this->url));
		} else {
			$this->debug(sprintf(__('Post ID: %s', 'kramer'), $this->post_id));

			if ($this->url != get_permalink($this->post_id) )
			$this->debug(sprintf(__('ERROR: %1$s was requested, but %2$s was fetched.', 'kramer'), $this->url, get_permalink($this->post_id)));

			$pings_allowed = $wpdb->get_var("SELECT ping_status FROM $wpdb->posts WHERE ID = '$this->post_id'");

			if ( !$pings_allowed )
				$this->debug(__('Pings have been disabled for this entry', 'kramer'));

			// This is a permalink
			if ( $this->auto_ref_pingback == 'yes' )
				$this->log_ref();

			// Storing synch value as meta key
			$this->post_synch_value = get_post_meta($this->post_id, '_kramer_synch', true);

			if (!$this->post_synch_value) {
				add_post_meta($this->post_id, '_kramer_synch', 99, true);
				$this->post_synch_value = 0;
				$this->debug(__('No last synch value found, creating now', 'kramer'));
			} else {
				$this->post_synch_value = (int) $this->post_synch_value;
			}

			$difference = time() - $this->post_synch_value;
			$this->debug(sprintf(__('Post Synch Value: %1$s Current Time: %2$s Difference: %3$s', 'kramer'), $this->post_synch_value, time(), $difference));


			if ( $this->post_synch_value < ( time() - $this->cache_expiry ) ) {
				$this->do_synch = true;
				update_post_meta($this->post_id, '_kramer_synch', time());
				$this->debug(__('Synch triggered by cache expiration', 'kramer'));
			} else {
				$this->debug(__('Cache is up to date for this entry', 'kramer'));
			}

			if ( $this->force_synch ) {
				$this->do_synch = true;
				$this->debug(__('Synch manually requested', 'kramer'));
			}

			if ( $this->do_synch ) {
				$this->debug(sprintf(__('Synching post id # %1$s (%2$s) from Technorati', 'kramer'), $this->post_id, $this->url));
				add_action('shutdown', array(&$this, 'technorati_get_cosmos'), 90);
			}

		} // [END] This is a permalink
	} // init()


	function technorati_get_cosmos() {
		$referer = $_SERVER["SCRIPT_URI"];

		if ( empty($this->api_key) ) {
			$this->debug(__('Empty API key, add one in the admin panel', 'kramer'));
			return -1;
		}

		// Nik uses this first request with a fixed URL for testing on his local server
		if ( $_SERVER['HTTP_HOST']=="server" ) {
			$request  = "GET http://api.technorati.com/cosmos?format=xml&url=" . 'http://www.perfected.org/archives/2005/05/07/poor-web-applications-and-pre-fetch-security-issues/' . "&key=" . $this->api_key . " HTTP/1.1\n";
		} else {
			$request  = "GET http://api.technorati.com/cosmos?format=xml&url=" . str_replace('http://', '', $this->url) . "&key=" . $this->api_key . " HTTP/1.1\n";
		}
		$request .= "Host: api.technorati.com\n";
		$request .= "Referer: $referer\n";
		$request .= "Connection: close\n";
		$request .= "\n";

		$fp = @fsockopen("api.technorati.com", 80, $error_num, $error_string, 2);
		if ( !$fp )
			return false;
		stream_set_timeout($fp, 5);
		fputs($fp, $request);
		while ( !feof($fp) )
			$result .= fgets($fp, 128);
		fclose($fp);

		$result = split("\r\n\r\n", $result, 2);
		$result = trim($result[1]);

		if(substr($result,0,5) != "<?xml") {
			$result = split("\r\n", $result, 2);
			$result = trim($result[1]);
		}

		$result = rtrim($result, " \r\n\t0");

		// uncomment this to debug the request and result
		// $this->debug(__('Request: ', 'kramer') . $request);
		// $this->debug(__('Result: ', 'kramer') . $result);

		$xml_parser = xml_parser_create();
		xml_set_element_handler($xml_parser, array(&$this, "parse_start"), array(&$this, "parse_end") );
		xml_set_character_data_handler($xml_parser, array(&$this, "parse_character_data") );

		if (!xml_parse($xml_parser, $result)) {
			$this->debug(sprintf(__('XML error: %1$s at line %2$s', 'kramer'), xml_error_string(xml_get_error_code($xml_parser)), xml_get_current_line_number($xml_parser) ));
			xml_parser_free($xml_parser);
		} else {
			xml_parser_free($xml_parser);
		}

		return $result;
	}


	function parse_character_data($parser, $data) {
		$this->current_tag = strtolower($this->current_tag);

		if ( $this->new_weblog ) {
			switch( $this->current_tag ) {
				case 'url':
					$this->weblog_url .= $data;
					break;
				case 'nearestpermalink':
					$this->weblog_entry_url .= $data;
					break;
				case 'name':
					$this->weblog_name .= $data;
					break;
				case 'linkcreated':
					$this->weblog_date .= $data;
					break;
				case 'excerpt':
					$this->weblog_content .= $data;
					break;
			}
		} else {
			if ( $this->current_tag == "error" )
				$this->parse_error .= $data;
		}
	}


	function parse_start($parser, $name, $attrb) {
		$this->current_tag = $name;

		switch($name) {
			case 'ITEM':
				$this->new_weblog = true;
				break;
		}
	}


	function parse_end($parser, $name) {
		switch($name) {
			case 'ERROR':
				$this->debug(__('ERROR: ', 'kramer') . trim($this->parse_error));
				break;
			// What we have now is the end of the last item
			// so we will hand of the data we have collected
			// to the inserting function which will decide if
			// it goes in or not
			case 'ITEM':
				if ( strlen($this->weblog_entry_url) < 2 )
					$this->weblog_entry_url = $this->weblog_url;

			$this->post_comment($this->weblog_name, $this->weblog_url, $this->weblog_date, $this->weblog_content, $this->weblog_entry_url);

			// reset everything for the next go-round
			$this->weblog_name = null;
			$this->weblog_url = null;
			$this->weblog_date = null;
			$this->weblog_content = null;
			$this->weblog_entry_url = null;
			$this->new_weblog = false;
			break;
		}

		$this->current_tag = null;
	}


	function debug($text) {
		$this->debug_output[] = $text;
	}


	function post_comment($name, $url, $date, $content, $permalink) {
		global $wpdb;

		// get new vars
		$this->Kramer();

		// Setup the content, add technorati bubble etc.
		// $content = "<a class=\"$this->technorati_balloon_class\" href=\"http://www.technorati.com/cosmos/search.html?url=" . $url . "\"><img src=\"http://static.technorati.com/images/bubble_h17.gif\" class=\"technorati-balloon\" alt=\"links from Technorati\" border=\"0\" /></a>" . $content;

		$content = '<!--%kramer-pre%-->' . $content . '<!--%kramer-post%-->';

		$parsed_url = parse_url($permalink);
		$domain = $parsed_url['host'];
		$domain = addslashes(str_replace('://www.', '://', $domain));

		$this->debug(sprintf(__('URL: %s Domain: %s', 'kramer'), $permalink, $domain));

		// clean it
		$content = addslashes($content);
		$name = addslashes($name);
		$permalink = addslashes($permalink);
		$domain = addslashes($domain);

		// Find out if we already have this entry, either
		// as a trackback or existing TR inbound and decide
		// if we are going to insert it into comments or not
		$sql = "SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID='$this->post_id' AND comment_author_url LIKE '%$domain%' AND (comment_type='pingback' OR comment_type='trackback')";
		$result = $wpdb->get_row($sql);

		$do_insert = ( empty($result) ) ? true : false;

		// block pings from your own site
		if ( strpos($permalink, get_bloginfo('home')) !== false ) $do_insert = false;
		if ( strpos($permalink, str_replace('http://', 'http://www.', get_bloginfo('home')) ) !== false ) $do_insert = false;
		if ( strpos($permalink, str_replace('http://www.', 'http://', get_bloginfo('home')) ) !== false ) $do_insert = false;

		if ( $do_insert ) {

			$sql = "INSERT INTO $wpdb->comments (comment_post_ID, comment_type, comment_author, comment_author_url, comment_date, comment_content, comment_author_IP) VALUES ('$this->post_id', '$this->comments_type', '$name', '$permalink', '$date', '$content', '127.0.0.1')";
			$wpdb->query($sql);

			$this->debug(sprintf(__('Post from %1$s inserted, linking from %2$s', 'kramer'), stripslashes($name), stripslashes($permalink)) );
			$comment_id = $wpdb->insert_id;

			if ( $this->email_notification=="yes" )
				add_action('init', create_function('$a=0', 'wp_notify_postauthor(' . $comment_id . ', \'pingback\');'));
		} else {
			$this->debug(sprintf(__('Post from %s already existed or was from this weblog', 'kramer'), stripslashes($name)) );
		}
	} // [END] post_comment


	function show_debug() {
		if ( count($this->debug_output) > 0 ) {
			echo "\n<!-- " . __('KRAMER DEBUG INFO', 'kramer') . " \n\n";
			foreach ($this->debug_output as $debug) {
				echo $debug . "\n";
			}
			echo "\n\n -->\n";
		}
	}


	function delete($id=0) {
		global $wpdb;

		$sql = "DELETE FROM $wpdb->comments WHERE comment_content LIKE '%<!--\%kramer-pre\%-->%'";

		if ( $id )
			$sql .= " AND comment_post_ID=$id";

		$res = $wpdb->query($sql);
		if ( $res )
			return sprintf(__('%s comments deleted', 'kramer'), $res);
		else
			return __('No comments deleted', 'kramer');
	}


	function options_page() {

		if(isset($_POST['kramer_submitted'])) {
			update_option('kramer_apikey', $_POST['kramer_apikey']);
			update_option('kramer_cacheexpiry', $_POST['kramer_cacheexpiry']);
			update_option('kramer_comments', $_POST['comments']);
			update_option('kramer_email_notification', $_POST['email_notification']);
			update_option('kramer_numitems', $_POST['numitems']);
			update_option('kramer_divname', $_POST['divname']);
			update_option('kramer_beforeblock', $_POST['beforeblock']);
			update_option('kramer_afterblock', $_POST['afterblock']);
			update_option('kramer_beforeitem', $_POST['beforeitem']);
			update_option('kramer_afteritem', $_POST['afteritem']);
			update_option('kramer_auto_ref_pingback', $_POST['auto_ref_pingback']);

			//get any new variables
			$this->Kramer();
		}

		if(isset($_POST['flush_cache'])) {
			$flush_cache = $this->flush_cache();
		}

		if(isset($_POST['delete_all'])) {
			$delete_all = $this->delete();
		}

		$numitems = get_option('kramer_numitems');
		$divname = get_option('kramer_divname');
		$beforeblock = get_option('kramer_beforeblock');
		$afterblock = get_option('kramer_afterblock');
		$beforeitem = get_option('kramer_beforeitem');
		$afteritem = get_option('kramer_afteritem');
		$email_notification = get_option('kramer_email_notification');
		$auto_ref_pingback = get_option('kramer_auto_ref_pingback');

		(empty($numitems)) ? $numitems = "10" : null;
		(empty($divname)) ? $divname = "kramer_inbound" : null;
		(empty($beforeblock)) ? $beforeblock = "<ul>" : null;
		(empty($afterblock)) ? $afterblock = "</ul>" : null;
		(empty($beforeitem)) ? $beforeitem = "<li>" : null;
		(empty($afteritem)) ? $afteritem = "</li>" : null;
		(empty($email_notification)) ? $email_yes = "CHECKED" : null;
		(empty($auto_ref_pingback)) ? $auto_ref_yes = "CHECKED" : null;

		if ( $email_notification == "no" ) {
			$email_no = "CHECKED";
		} else {
			$email_yes = "CHECKED";
		}
		
		if ( $auto_ref_pingback == "no") {
			$auto_ref_no = "CHECKED";
		} else {
			$auto_ref_yes = "CHECKED";
		}

		$var[$this->cache_expiry] = "SELECTED";

		$formaction = $_SERVER['PHP_SELF'] . "?page=kramer.php";

		// Check if there is a new version of Kramer
		$version_synch_val = get_option('kramer_check_version');

		if ( empty($version_synch_val) )
			add_option('kramer_check_version', '0');

		if (get_option('kramer_check_version') < ( time() - $this->cache_expiry ) ) {
			$latest_version = $this->check_for_updates();
			update_option('kramer_check_version', time());
			update_option('kramer_latest_version', $latest_version);
		} else {
			$latest_version = get_option('kramer_latest_version');
		}

		if ($this->version != $latest_version )
			$update = "(<a href=\"http://dev.wp-plugins.org/wiki/Kramer\">update</a>)";

// Start outputting XHMTL
?>
		<div class="wrap">
			<h2><?php _e('Kramer Options', 'kramer'); ?></h2>

			<form name="kramer_options" method="post" action="<?php echo $formaction; ?>">
			<input type="hidden" name="kramer_submitted" value="1" />

			<fieldset class="options">
				<legend>
					<label><?php _e('API Key', 'kramer'); ?></label>
				</legend>

				<p>
				<?php _e('Kramer requires a valid Technorati API key to access inbound link information for your weblog via their API.
				To get a key, register as a user at <a href="http://www.technorati.com">Technorati</a> and then go to
				<a href="http://www.technorati.com/developers/apikey.html">http://www.technorati.com/developers/apikey.html</a> to request
				your key.', 'kramer'); ?>

				<?php _e('Copy your key below, and update the options. Please note that there is a 500 query per day limit with each API key,
				and you should take that into consideration when setting your cache settings.', 'kramer'); ?>
				</p>

				<table width="100%" cellspacing="2" cellpadding="5" class="editform">
				<tr>
					<th width="33%" valign="top" scope="row"><?php _e('API Key:', 'kramer'); ?> </th>
					<td>
						<input name="kramer_apikey" type="text" id="kramer_apikey" value="<?php echo $this->api_key; ?>" size="50" /><br />
					</td>
				</tr>
				</table>
			</fieldset>

			<fieldset class="options">
				<legend>
					<label><?php _e('Other options', 'kramer'); ?></label>
				</legend>

				<p><?php _e('Miscellaneous settings.', 'kramer'); ?></p>

				<table width="100%" cellspacing="2" cellpadding="5" class="editform">
				<tr>
					<th width="33%" valign="top" scope="row"><?php _e('Email notification:', 'kramer'); ?> </th>
					<td>
						<input type="radio" name="email_notification" value="yes" <?php echo $email_yes; ?>> <?php _e('Yes', 'kramer'); ?><br />
						<input type="radio" name="email_notification" value="no" <?php echo $email_no; ?>> <?php _e('No', 'kramer'); ?>
					</td>
				</tr>
				<tr>
					<th width="33%" valign="top" scope="row"><?php _e('Auto Ref Pingback:', 'kramer'); ?> </th>
					<td>
						<input type="radio" name="auto_ref_pingback" value="yes" <?php echo $auto_ref_yes; ?>> <?php _e('Yes', 'kramer'); ?><br />
						<input type="radio" name="auto_ref_pingback" value="no" <?php echo $auto_ref_no; ?>> <?php _e('No', 'kramer'); ?>
					</td>
				</tr>
				</table>

				<p>
				<?php _e('Set the cache options, which will also apply to the front page inbound link list. Only use short time periods for testing since Technorati currently has a hard limit of 500 requests per day.', 'kramer'); ?>
				</p>

				<p>
				<?php _e('<i>Expire cache now</i> will expire all caches and will force Kramer to re-synch at the next page request. <i>Delete all Technorati comments</i> will remove all comments added by Kramer. They will be re-added again from Technorati dynamically as the posts are visited.', 'kramer'); ?>
				</p>

				<table width="100%" cellspacing="2" cellpadding="5" class="editform">
				<tr>
					<th width="33%" valign="top" scope="row"><?php _e('Cache expiration:', 'kramer'); ?> </th>
					<td>
						<select name="kramer_cacheexpiry">
							<option value="60" <?php echo $var[60]; ?>><?php _e('1 minute', 'kramer'); ?></option>
							<option value="1800" <?php echo $var[1800]; ?>><?php _e('30 minutes', 'kramer'); ?></option>
							<option value="3600" <?php echo $var[3600]; ?>><?php _e('1 hour', 'kramer'); ?></option>
							<option value="10800" <?php echo $var[10800]; ?>><?php _e('3 hours', 'kramer'); ?></option>
							<option value="21600" <?php echo $var[21600]; ?>><?php _e('6 hours', 'kramer'); ?></option>
							<option value="43200" <?php echo $var[43200]; ?>><?php _e('12 hours', 'kramer'); ?></option>
							<option value="86400" <?php echo $var[86400]; ?>><?php _e('24 hours', 'kramer'); ?></option>
						</select>

					</td>
				</tr>
				<tr>
					<th width="33%" valign="top" scope="row"></th>
					<td>
						<input type="submit" name="flush_cache" value="<?php _e('Expire cache now', 'kramer'); ?>" /> <span style="color: red;">
						<?php echo $flush_cache; ?>
						</span>
					</td>
				</tr>
				<tr>
					<th width="33%" valign="top" scope="row"><?php _e('Comments:', 'kramer'); ?> </th>
					<td>

						<input type="submit" name="delete_all" value="<?php _e('Delete all Technorati comments', 'kramer'); ?>" onclick="return confirm('<?php _e('Are you sure you want to delete all Technorati comments?', 'kramer'); ?>')" /> <span style="color: red;"><?php echo $delete_all; ?></span>
					</td>
				</tr>
				<tr>
					<th width="33%" valign="top" scope="row"></th>
					<td>
					<?php printf(__('This version of Kramer is %1$s and the latest version is %2$s %3$s', 'kramer'), $this->version, $latest_version, $update); ?>
					</td>
				</tr>
				</table>
			</fieldset>

			<fieldset class="options">
				<legend>
					<label><?php _e('Sidebar options', 'kramer'); ?></label>
				</legend>

				<p>
				<?php _e('To include a list of inbound links on your main weblog page, include the function <code>kramer_inbound();</code> where you want
				it to be displayed in your template. This function requires no paramaters as the options are set here.', 'kramer'); ?></p>

				<table width="100%" cellspacing="2" cellpadding="5" class="editform">
				<tr>
					<th width="33%" valign="top" scope="row"><?php _e('Number of Entries:', 'kramer'); ?> </th>
					<td>
						<input type="text" name="numitems" value="<?php echo $numitems; ?>" /><br />
						<i><?php _e('Display the last x number of entries on the list. Default is 10', 'kramer'); ?></i>
					</td>
				</tr>

				<tr>
					<th width="33%" valign="top" scope="row"><?php _e('Name of container:', 'kramer'); ?> </th>
					<td>
						<input type="text" name="divname" value="<?php echo $divname; ?>" /><br />
						<i><?php _e('Name of list container, use this to set a style in your stylesheet. Default is kramer_inbound', 'kramer'); ?></i>
					</td>
				</tr>

				<tr>
					<th width="33%" valign="top" scope="row"><?php _e('Insert before list:', 'kramer'); ?> </th>
					<td>
						<input type="text" name="beforeblock" value="<?php echo $beforeblock; ?>" /><br />
						<i><?php _e('HTML element to include before the list block. Default is &lt;ul&gt; (unordered list)', 'kramer'); ?></i>
					</td>
				</tr>

				<tr>
					<th width="33%" valign="top" scope="row"><?php _e('Insert after list:', 'kramer'); ?> </th>
					<td>
						<input type="text" name="afterblock" value="<?php echo $afterblock; ?>" /><br />
						<i><?php _e('HTML element to include after the list block. Default is &lt;/ul&gt; (end of unordered list)', 'kramer'); ?></i>
					</td>
				</tr>

				<tr>
					<th width="33%" valign="top" scope="row"><?php _e('Insert before list item:', 'kramer'); ?> </th>
					<td>
						<input type="text" name="beforeitem" value="<?php echo $beforeitem; ?>" /><br />
						<i><?php _e('HTML element to place before each list item. Default is &lt;li&gt; (list item)', 'kramer'); ?></i>
					</td>
				</tr>

				<tr>
					<th width="33%" valign="top" scope="row"><?php _e('Insert after list item:', 'kramer'); ?> </th>
					<td>
						<input type="text" name="afteritem" value="<?php echo $afteritem; ?>" /><br />
						<i><?php _e('HTML element to place after each list item. Default is &lt;li&gt; (end of list item)', 'kramer'); ?></i>
					</td>
				</tr>
				</table>
			</fieldset>


			<p class="submit">
				<input type="submit" name="Submit" value="<?php _e('Update Options &raquo;', 'kramer'); ?>" />
			</p>
		</form>
		</div>

<?php }


	function flush_cache() {
		global $wpdb;
		$postmeta = $wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key = '_kramer_synch'");
		$count += $postmeta;
		return sprintf(__('flushed %s items down', 'kramer'), $count);
	}


	function flush_depreciated_options() {
		global $wpdb, $wp_queries;

		// delete meta keys we no longer user
		$wpdb->query("DELETE FROM $wpdb->postmeta WHERE `meta_key` = '_kramer_ref' || `meta_key` = '_kramer_ref_processed' || `meta_key` = '_kramer_ref_ignored'");

		// delete options we no longer use
		delete_option('kramer_comments');

		$wpdb->hide_errors();
		$options = $wpdb->get_results("SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE '%kramer_synch_%'");
		$wpdb->show_errors();

		// convert old style comments
		$comments = $wpdb->get_results("SELECT comment_ID, comment_content FROM $wpdb->comments WHERE comment_content LIKE '%http://static.technorati.com/images/bubble_h17.gif%'");

		if ( $comments ) {
		foreach ( $comments as $comment ) {
			$content = preg_replace('/<a (.*)static\.technorati\.com\/images\/bubble_h17\.gif"(.*)<\/a>/', '<!--%kramer-pre%-->', $comment->comment_content);
			$content .= '<!--%kramer-post%-->';
			$content = $wpdb->escape($content);
			$wpdb->query("UPDATE $wpdb->comments SET comment_content = '$content' WHERE comment_ID = '$comment->comment_ID'");
		} // foreach ($comments as $comment)
		} // if ( $comments )

		$count = 0;

		if ( $options ) {
		foreach ( $options as $option ) {
			if ('siteurl' == $option->option_name) $option->option_value = preg_replace('|/+$|', '', $option->option_value);
			if ('home' == $option->option_name) $option->option_value = preg_replace('|/+$|', '', $option->option_value);
			if ('category_base' == $option->option_name) $option->option_value = preg_replace('|/+$|', '', $option->option_value);
			@ $value = unserialize($option->option_value);
			if ($value === false)
			$value = $option->option_value;
			$all_options->{$option->option_name} = apply_filters('pre_option_' . $option->option_name, $value);
		}

		$all_options = apply_filters('all_options', $all_options);

		foreach( $all_options as $t => $k ) {
			if(!strncmp($t, "kramer_synch_", 13)) {
				delete_option($t);
				$count++;
			}
		}

	} // if ( $options)

		return $count;
	}


	function check_for_updates() {
		$this->flush_depreciated_options();
		$request  = "GET http://svn.wp-plugins.org/kramer/trunk/latest-version.txt HTTP/1.1\n";
		$request .= "Host: svn.wp-plugins.org\n";
		$request .= "Referer: " . $_SERVER["SCRIPT_URI"] . "\n";
		$request .= "Connection: close\n";
		$request .= "\n";

		$fp = @fsockopen("svn.wp-plugins.org", 80);
		if ( !$fp )
			return 'unknown';
		fputs($fp, $request);
		while(!feof($fp)) {
			$result .= fgets($fp, 128);
		}
		fclose($fp);

		$result = split("\r\n", $result);

		foreach($result as $k) {
			if(!strncmp($k, "Version: ", 9)) {
				$result = $k;
				break;
			}
		}

		$version = split(": ", $k);
		$version = $version[1];

		return $version;
	}


	function log_ref() {
		global $wpdb;
		if ( !$this->post_id ) return false;
		if ( strpos($this->ref, 'http://') === false ) return false;
		if ( strpos($this->ref, '.') === false ) return false;

		if ( strpos($this->ref, get_bloginfo('home')) !== false ) return false;
		if ( strpos($this->ref, str_replace('http://', 'http://www.', get_bloginfo('home')) ) !== false ) return false;
		if ( strpos($this->ref, str_replace('http://www.', 'http://', get_bloginfo('home')) ) !== false ) return false;

		$pending_refs = get_post_meta($this->post_id, '_kramer_ref_pending');
		// $ignored_refs = get_post_meta($this->post_id, '_kramer_ref_ignored');
		$failed_refs = get_post_meta($this->post_id, '_kramer_ref_failed');
		$successful_refs = get_post_meta($this->post_id, '_kramer_ref_ping');

		if ( in_array($this->ref, (array) $pending_refs) || in_array($this->ref, (array) $successful_refs) || in_array($this->ref, (array) $failed_refs) ) return false;

		// Add it as pending to protect against rapid-fire double-pingbacks
		add_post_meta($this->post_id, '_kramer_ref_pending', addslashes($this->ref));

		if ( !$this->filter_ref($this->ref) ) {
			$this->ignore_ref();
			return;
		}

		if ( substr_count($this->ref, '/') < 5 && (strpos($this->ref, '?') === false && strpos($this->ref, '.htm') === false && strpos($this->ref, '.shtml') === false) ) {
			$this->ignore_ref();
			return;
		}

		if ( substr($this->ref, -9, 9) == 'index.php' ) {
			$this->ignore_ref();
			return;
		}

		$parsed_url = parse_url($this->ref);
		$domain = $parsed_url['host'];
		$domain = str_replace('://www.', '://', $domain);

		if ( !isset($parsed_url['query']) && ($parsed_url['path'] == '/' || $parsed_url['path'] == '') ) {
			$this->ignore_ref();
			return;
		}

		$sql = "SELECT comment_ID FROM $wpdb->comments WHERE comment_post_ID='$this->post_id' AND comment_author_url LIKE '%$domain%' AND (comment_type='pingback' OR comment_type='trackback')";
		$already_pinged = $wpdb->get_var($sql);

		if ( $already_pinged ) {
			$this->ignore_ref();
			return false;
		}


		// get the links from the post
		$post = &get_post($this->post_id);
		$post_links = $this->extract_links($post->post_content);
		$post_links = ( is_array($post_links) ) ? $post_links : array();

		if ( @in_array($this->ref, $post_links) ) {
			$this->ignore_ref();
			return;
		}

		// We're setting it as "failed" only temporarily.  If the pingback is successful, it'll be switched.
		// If the pingback fails, then it will remain (appropriately, now) as "failed"
		$wpdb->query("UPDATE $wpdb->postmeta SET meta_key = '_kramer_ref_failed' WHERE meta_key = '_kramer_ref_pending' AND post_id = '$this->post_id' AND meta_value = '" . addslashes($this->ref) . "'");

		// pingback this ref on shutdown
		// add_action('shutdown', create_function('$a', 'global $kramer; $kramer->pingback(array($kramer->ref), $kramer->post_id);'));
		add_action('shutdown', array(&$this, 'pingback'));
	}


	// to be used as an array_filter() callback to strip out obvious non-blog refs
	function filter_ref($ref) {
		$ref = strtolower($ref);

		$bad_ref_fragments = array(
		'+++++',
		'127.0.0.1',
		'.a9.com',
		'//a9.com',
		'aimexpress.aim.com',
		'altavista.com',
		'awstats',
		'bloglines.',
		'blogpulse.com',
		'cat=',
		'/category/',
		'comments.cgi',
		'del.icio.us',
		'doteasy.com',
		'feedburner.com',
		'furl.net',
		'google.',
		'hotbot.com',
		'keywords=',
		'kinja',
		'localhost',
		'mailcenter',
		'mailredirect',
		'metacrawler.',
		'newsisfree.',
		'/page/',
		'page=search',
		'popdex.',
		'&q=',
		'?q=',
		'qry=',
		'query=',
		'readme.html',
		'&s=',
		'?s=',
		'?search',
		'search.',
		'search?',
		'/search',
		'sitemeter.com',
		'statcounter',
		'string=',
		'/tag/',
		'tb.cgi',
		'technorati.',
		'thefeeddirectory.com',
		'tinyurl.',
		'trac.wordpress.org',
		'translate_c',
		'webmessenger.msn.com',
		'wholinkstome.com',
		'/wp-admin/',
		'wp-plugins.net',
		'wp-plugins.org',
		'yahoo.'
		);

		foreach ($bad_ref_fragments as $frag) {
		if ( strpos($ref, $frag) !== false ) return false;
		}

		return true;
	}


	function ignore_ref($uri=false, $post_ID=false) {
		$uri = ($uri) ? $uri : $this->ref;
		$post_ID = ($post_ID) ? $post_ID : $this->post_id;

		$uri = addslashes($uri);
		// depreciating this, as it tends to bloat the database
		// add_post_meta($post_ID, '_kramer_ref_ignored', $uri);
		delete_post_meta($post_ID, '_kramer_ref_pending', $uri);
	}


	function pingback($from_array=0, $post_ID=0) {
		global $wp_version, $wpdb;

		if ( !$from_array )
			$from_array = array($this->ref);

		if ( !$post_ID )
			$post_ID = $this->post_id;

		include_once (ABSPATH . WPINC . '/class-IXR.php');

	// Copied from WordPress's pingback() function

	// original code by Mort (http://mort.mine.nu:8080)
	$log = debug_fopen(ABSPATH . '/pingback.log', 'a');
	debug_fwrite($log, 'BEGIN '.date('YmdHis', time())."\n");
	debug_fwrite($log, 'Pingback generated by Kramer ' . $this->version . "\n");

	// $post = &get_post($post_ID);
	$pagelinkedto = $this->url;

	$pingback_server_url = get_bloginfo('pingback_url');

		foreach ($from_array as $pagelinkedfrom) {

		debug_fwrite($log, "Processing -- $pagelinkedfrom\n");

			set_time_limit( 60 );
			// Now, the RPC call
			debug_fwrite($log, "Page Linked To: $pagelinkedto \n");
			debug_fwrite($log, 'Page Linked From: ');

			// using a timeout of 3 seconds should be enough to cover slow servers
			$client = new IXR_Client($pingback_server_url);
			$client->timeout = 3;
			$client->useragent .= ' -- Kramer/' . $this->version . ' -- WordPress/' . $wp_version;

			// when set to true, this outputs debug messages by itself
			$client->debug = false;

			if ( $client->query('pingback.ping', array($pagelinkedfrom, $pagelinkedto) ) ) {
				// do nothing, we handle it when the actual pingback comes in
			} else {
				debug_fwrite($log, "Error.\n Fault code: ".$client->getErrorCode()." : ".$client->getErrorMessage()."\n");
			}
		}

	debug_fwrite($log, "\nEND: ".time()."\n****************************\n");
	debug_fclose($log);
	}


	function extract_links($text) {
		// Variables
		$ltrs = '\w';
		$gunk = '/#~:.?+=&%@!\-';
		$punc = '.:?\-';
		$any = $ltrs . $gunk . $punc;

		// Step 1
		// Parsing the post, external links (if any) are stored in the $post_links array
		// This regexp comes straight from phpfreaks.com
		// http://www.phpfreaks.com/quickcode/Extract_All_URLs_on_a_Page/15.php
		preg_match_all("{\b http : [$any] +? (?= [$punc] * [^$any] | $)}x", $text, $post_links);

		return $post_links[0];
	}


	function examine_incoming_pingback($comment) {
		if ($comment['comment_type'] != 'pingback') return $comment;
		global $wpdb;

		$post_id = (int) $comment['comment_post_ID'];
		$uri = str_replace('&amp;', '&', $comment['comment_author_url']);

		$ref_ping = $wpdb->get_var("SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = '_kramer_ref_failed' AND post_id = '$post_id' AND meta_value = '$uri'");

		if ( $ref_ping ) {
			$wpdb->query("UPDATE $wpdb->postmeta SET meta_key = '_kramer_ref_ping' WHERE meta_id = '$ref_ping' LIMIT 1");
			add_filter('pre_comment_content', create_function('$a', 'return \'<!--%kramer-ref-pre%-->\' . $a . \'<!--%kramer-ref-post%-->\';'), 90); // Not adding it dead last because the Preformatted plugin grabs it at 99
		}

		return $comment;
	}


	function convert_ping_tags($text) {
	// TODO: allow the user to choose before/after and customize the text
	$text = str_replace('<!--%kramer-pre%-->', "<a class=\"$this->technorati_balloon_class\" href=\"http://www.technorati.com/cosmos/search.html?url=" . $url . "\"><img src=\"http://static.technorati.com/images/bubble_h17.gif\" class=\"technorati-balloon\" alt=\"links from Technorati\" style=\"border:0;\" /></a>", $text);

	$text = str_replace('<!--%kramer-post%-->', '', $text);

	$text = str_replace('<!--%kramer-ref-pre%-->', "<a href=\"http://dev.wp-plugins.org/wiki/Kramer\"><img src=\"http://" . $_SERVER['HTTP_HOST'] . str_replace($_SERVER['DOCUMENT_ROOT'], '', __FILE__) . '?kramer=gif-icon' . "\" class=\"technorati-balloon\" alt=\"Kramer auto Pingback\" style=\"border:0;\" /></a>", $text);

	$text = str_replace('<!--%kramer-ref-post%-->', '', $text);

	return $text;
	}

	// TODO: modify this funtion so that it is given a post ID and will ping all the referrers for that post
	// by pulling the metadata info
	function technorati_ping($urls) {
		$host = "www.technorati.com";
		$referer = $_SERVER['SCRIPT_URI'];

		$fp = @fsockopen($host, 80);

		if ( !$fp ) {
				$this->debug('ERROR: Could not ping Technorati');
				return 0;
		}

		// we wont wait for the response, just keep sending the pings.
		foreach($urls as $u) {
			$u = urlencode($u);

			$request  = "GET http://www.technorati.com/developers/ping.html?url=$u HTTP/1.1\n";
			$request .= "Host: $host\n";
			$request .= "Referer: $referer\n";
			$request .= "Connection: keep-alive\n";
			$request .= "\n";

			if(fputs($fp, $request) === false) {
				$this->debug('ERROR: Could not ping Technorati');
				return 0;
			} else {
				$this->debug('Pinged $url');
			}
		}

		// close the keep-alive and the socket
		$request = "HEAD / HTTP/1.1\nHost: $host\nReferer: $referer\nConnection: close\n\n";
		fputs($fp, $request);
		fclose($fp);

		return 1;
	}

	function admin_menu() {
	add_options_page(__('Kramer', 'kramer'), __('Kramer', 'kramer'), 'manage_options', __FILE__, array(&$this, 'options_page'));
	}

} // Class Kramer


// kramer_inbound. use this funtion to display inbound links for the weblog in your sidebar.
function kramer_inbound() {
	global $wpdb;

	$kramer = new Kramer;

	$numitems = get_option('kramer_numitems');
	$divname = get_option('kramer_divname');
	$beforeblock = get_option('kramer_beforeblock');
	$afterblock = get_option('kramer_afterblock');
	$beforeitem = get_option('kramer_beforeitem');
	$afteritem = get_option('kramer_afteritem');

	(empty($numitems)) ? $numitems = "10" : null;
	(empty($divname)) ? $divname = "kramer_inbound" : null;
	(empty($beforeblock)) ? $beforeblock = "<ul>" : null;
	(empty($afterblock)) ? $afterblock = "</ul>" : null;
	(empty($beforeitem)) ? $beforeitem = "<li>" : null;
	(empty($afteritem)) ? $afteritem = "</li>" : null;

	$post_synch_val = get_option('kramer_synch_inbound');

	// Work out if we should synch
	if ( empty($post_synch_val) )
		add_option('kramer_synch_inbound', '0');

	if ( get_option('kramer_synch_inbound') < ( time() - $kramer->cache_expiry ) ) {
		update_option('kramer_synch_inbound', time());
		register_shutdown_function(create_function('$a=0', 'global $kramer; $kramer->technorati_get_cosmos();'));
	}

	// Rack up all the DB entries into a nice list to send back
	// take into account that we only want the last x links
	$entries = "";
	$x = 1;
	$sql = "SELECT comment_author, comment_author_url, comment_date FROM $wpdb->comments where comment_post_ID = 0 ORDER BY comment_date DESC;";
	$results = $wpdb->get_results($sql);
	if ($results) {
		foreach ($results as $result) {
			$entries .= "$beforeitem<a href=\"$result->comment_author_url\">$result->comment_author</a>$afteritem";
			$x++;
			if($x > $numitems)
				break;
		}
	}

	// Print the list back
	echo <<<END
		<div id="$divname">
			$beforeblock
				$entries
			$afterblock
		</div>
END;
}

add_action('init', create_function('$a=0', 'global $kramer; $kramer = new Kramer; $kramer->init();'));

?>