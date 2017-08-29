<?php

# fix for mozBlog and other cases where '<?xml' isn't on the very first line
$HTTP_RAW_POST_DATA = trim($HTTP_RAW_POST_DATA);

include('wp-config.php');

include_once (ABSPATH . WPINC . '/class-xmlrpc.php');
include_once (ABSPATH . WPINC . '/class-xmlrpcs.php');

// Turn off all warnings and errors.
error_reporting(0);

$post_default_title = ""; // posts submitted via the xmlrpc interface get that title
$post_default_category = 1; // posts submitted via the xmlrpc interface go into that category

$xmlrpc_logging = 0;

function logIO($io,$msg) {
	global $xmlrpc_logging;
	if ($xmlrpc_logging) {
		$fp = fopen("./xmlrpc.log","a+");
		$date = gmdate("Y-m-d H:i:s ");
		$iot = ($io == "I") ? " Input: " : " Output: ";
		fwrite($fp, "\n\n".$date.$iot.$msg);
		fclose($fp);
	}
	return true;
	}

function starify($string) {
	$i = strlen($string);
	return str_repeat('*', $i);
}

logIO("I", $HTTP_RAW_POST_DATA);

/**** DB Functions ****/

/*
 * These really should be moved into wp-includes/functions.php,
 * and re-used throughout the code, where possible. -- emc3
 */

/*
 * generic function for inserting data into the posts table.
 */
function wp_insert_post($postarr = array()) {
	global $wpdb, $tableposts, $post_default_category;
	
	// export array as variables
	extract($postarr);
	
	// Do some escapes for safety
	$post_title = $wpdb->escape($post_title);
	$post_name = sanitize_title($post_title);
	$post_excerpt = $wpdb->escape($post_excerpt);
	$post_content = $wpdb->escape($post_content);
	$post_author = (int) $post_author;

	// Make sure we set a valid category
	if (0 == count($post_category) || !is_array($post_category)) {
		$post_category = array($post_default_category);
	}

	$post_cat = $post_category[0];
	
	if (empty($post_date))
		$post_date = current_time('mysql');
	// Make sure we have a good gmt date:
	if (empty($post_date_gmt)) 
		$post_date_gmt = get_gmt_from_date($post_date);
	
	$sql = "INSERT INTO $tableposts 
		(post_author, post_date, post_date_gmt, post_modified, post_modified_gmt, post_content, post_title, post_excerpt, post_category, post_status, post_name) 
		VALUES ('$post_author', '$post_date', '$post_date_gmt', '$post_date', '$post_date_gmt', '$post_content', '$post_title', '$post_excerpt', '$post_cat', '$post_status', '$post_name')";
	
	$result = $wpdb->query($sql);
	$post_ID = $wpdb->insert_id;
	
	wp_set_post_cats('',$post_ID,$post_category);
	
	// Return insert_id if we got a good result, otherwise return zero.
	return $result ? $post_ID : 0;
}

function wp_get_single_post($postid = 0, $mode = OBJECT) {
	global $wpdb, $tableposts;

	$sql = "SELECT * FROM $tableposts WHERE ID=$postid";
	$result = $wpdb->get_row($sql, $mode);
	
	// Set categories
	$result['post_category'] = wp_get_post_cats('',$postid);

	return $result;
}

function wp_get_recent_posts($num = 10) {
	global $wpdb, $tableposts;

	// Set the limit clause, if we got a limit
	if ($num) {
		$limit = "LIMIT $num";
	}

	$sql = "SELECT * FROM $tableposts ORDER BY post_date DESC $limit";
	$result = $wpdb->get_results($sql,ARRAY_A);

	return $result?$result:array();
}

function wp_update_post($postarr = array()) {
	global $wpdb, $tableposts;

	// First get all of the original fields
	extract(wp_get_single_post($postarr['ID'],ARRAY_A));	

	// Now overwrite any changed values being passed in
	extract($postarr);
	
	// Make sure we set a valid category
	if (0 == count($post_category) || !is_array($post_category)) {
		$post_category = array($post_default_category);
	}

	// Do some escapes for safety
	$post_title = $wpdb->escape($post_title);
	$post_excerpt = $wpdb->escape($post_excerpt);
	$post_content = $wpdb->escape($post_content);

	$post_modified = current_time('mysql');
	$post_modified_gmt = current_time('mysql', 1);

	$sql = "UPDATE $tableposts 
		SET post_content = '$post_content',
		post_title = '$post_title',
		post_category = $post_category[0],
		post_status = '$post_status',
		post_date = '$post_date',
		post_date_gmt = '$post_date_gmt',
		post_modified = '$post_modified',
		post_modified_gmt = '$post_modified_gmt',
		post_excerpt = '$post_excerpt',
		ping_status = '$ping_status',
		comment_status = '$comment_status'
		WHERE ID = $ID";
		
	$result = $wpdb->query($sql);

	wp_set_post_cats('',$ID,$post_category);
	
	return $wpdb->rows_affected;
}

function wp_get_post_cats($blogid = '1', $post_ID = 0) {
	global $wpdb, $tablepost2cat;
	
	$sql = "SELECT category_id 
		FROM $tablepost2cat 
		WHERE post_id = $post_ID 
		ORDER BY category_id";

	$result = $wpdb->get_col($sql);

	return array_unique($result);
}

function wp_set_post_cats($blogid = '1', $post_ID = 0, $post_categories = array()) {
	global $wpdb, $tablepost2cat;
	// If $post_categories isn't already an array, make it one:
	if (!is_array($post_categories)) {
		if (!$post_categories) {
			$post_categories = 1;
		}
		$post_categories = array($post_categories);
	}

	$post_categories = array_unique($post_categories);

	// First the old categories
	$old_categories = $wpdb->get_col("
		SELECT category_id 
		FROM $tablepost2cat 
		WHERE post_id = $post_ID");
	
	if (!$old_categories) {
		$old_categories = array();
	} else {
		$old_categories = array_unique($old_categories);
	}


	$oldies = print_r($old_categories,1);
	$newbies = print_r($post_categories,1);

	logio("O","Old: $oldies\nNew: $newbies\n");

	// Delete any?
	$delete_cats = array_diff($old_categories,$post_categories);

	logio("O","Delete: " . print_r($delete_cats,1));
		
	if ($delete_cats) {
		foreach ($delete_cats as $del) {
			$wpdb->query("
				DELETE FROM $tablepost2cat 
				WHERE category_id = $del 
					AND post_id = $post_ID 
				");

			logio("O","deleting post/cat: $post_ID, $del");
		}
	}

	// Add any?
	$add_cats = array_diff($post_categories, $old_categories);

	logio("O","Add: " . print_r($add_cats,1));
		
	if ($add_cats) {
		foreach ($add_cats as $new_cat) {
			$wpdb->query("
				INSERT INTO $tablepost2cat (post_id, category_id) 
				VALUES ($post_ID, $new_cat)");

				logio("O","adding post/cat: $post_ID, $new_cat");
		}
	}
}	// wp_set_post_cats()

function wp_delete_post($postid = 0) {
	global $wpdb, $tableposts, $tablepost2cat;
	
	$sql = "DELETE FROM $tablepost2cat WHERE post_id = $postid";
	$wpdb->query($sql);
		
	$sql = "DELETE FROM $tableposts WHERE ID = $postid";
	
	$wpdb->query($sql);

	$result = $wpdb->rows_affected;
	
	return $result;
}

/**** /DB Functions ****/

/**** Misc ****/

// get permalink from post ID
function post_permalink($post_ID=0, $mode = 'id') {
    global $wpdb;
	global $tableposts;
	global $querystring_start, $querystring_equal, $querystring_separator;

	$blog_URL = get_settings('home') .'/'. get_settings('blogfilename');

	$postdata = get_postdata($post_ID);

	// this will probably change to $blog_ID = $postdata['Blog_ID'] one day.
	$blog_ID = 1;

	if (!($postdata===false)) {
	
		switch(strtolower($mode)) {
			case 'title':
				$title = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $postdata['Title']);
				break;
			case 'id':
			default:
				$title = "post-$post_ID";
				break;
		}

		// this code is blatantly derived from permalink_link()
		$archive_mode = get_settings('archive_mode');
		switch($archive_mode) {
			case 'daily':
				$post_URL = $blog_URL.$querystring_start.'m'.$querystring_equal.substr($postdata['Date'],0,4).substr($postdata['Date'],5,2).substr($postdata['Date'],8,2).'#'.$title;
				break;
			case 'monthly':
				$post_URL = $blog_URL.$querystring_start.'m'.$querystring_equal.substr($postdata['Date'],0,4).substr($postdata['Date'],5,2).'#'.$title;
				break;
			case 'weekly':
				if((!isset($cacheweekly)) || (empty($cacheweekly[$postdata['Date']]))) {
					$sql = "SELECT WEEK('".$postdata['Date']."') as wk";
	                    $row = $wpdb->get_row($sql);
					$cacheweekly[$postdata['Date']] = $row->wk;
				}
				$post_URL = $blog_URL.$querystring_start.'m'.$querystring_equal.substr($postdata['Date'],0,4).$querystring_separator.'w'.$querystring_equal.$cacheweekly[$postdata['Date']].'#'.$title;
				break;
			case 'postbypost':
				$post_URL = $blog_URL.$querystring_start.'p'.$querystring_equal.$post_ID;
				break;
		}
	} 

	return $post_URL;
}

// Get the name of a category from its ID
function get_cat_name($cat_id) {
	global $wpdb,$tablecategories;
	
	$cat_id -= 0; 	// force numeric
	$name = $wpdb->get_var("SELECT cat_name FROM $tablecategories WHERE cat_ID=$cat_id");
	
	return $name;
}

// Get the ID of a category from its name
function get_cat_ID($cat_name='General') {
	global $wpdb,$tablecategories;
	
	$cid = $wpdb->get_var("SELECT cat_ID FROM $tablecategories WHERE cat_name='$cat_name'");

	return $cid?$cid:1;	// default to cat 1
}

// Get author's preferred display name
function get_author_name($auth_id) {
	$authordata = get_userdata($auth_id);

	switch($authordata["user_idmode"]) {
		case "nickname":
			$authorname = $authordata["user_nickname"];

		case "login":
			$authorname = $authordata["user_login"];
			break;
	
		case "firstname":
			$authorname = $authordata["user_firstname"];
			break;

		case "lastname":
			$authorname = $authordata["user_lastname"];
			break;

		case "namefl":
			$authorname = $authordata["user_firstname"]." ".$authordata["user_lastname"];
			break;

		case "namelf":
			$authorname = $authordata["user_lastname"]." ".$authordata["user_firstname"];
			break;

		default:
			$authorname = $authordata["user_nickname"];
			break;
	}

	return $authorname;
}

// get extended entry info (<!--more-->)
function get_extended($post) {
	list($main,$extended) = explode('<!--more-->',$post);

	// Strip leading and trailing whitespace
	$main = preg_replace('/^[\s]*(.*)[\s]*$/','\\1',$main);
	$extended = preg_replace('/^[\s]*(.*)[\s]*$/','\\1',$extended);

	return array('main' => $main, 'extended' => $extended);
}

// do trackbacks for a list of urls
// borrowed from edit.php
// accepts a comma-separated list of trackback urls and a post id
function trackback_url_list($tb_list, $post_id) {
	if (!empty($tb_list)) {
		// get post data
		$postdata = wp_get_single_post($post_id, ARRAY_A);

		// import postdata as variables
		extract($postdata);
		
		// form an excerpt
		$excerpt = strip_tags($post_excerpt?$post_excerpt:$post_content);
		
		if (strlen($excerpt) > 255) {
			$exerpt = substr($excerpt,0,252) . '...';
		}
		
		$trackback_urls = explode(',', $tb_list);
		foreach($trackback_urls as $tb_url) {
		    $tb_url = trim($tb_url);
		    trackback($tb_url, stripslashes($post_title), $excerpt, $post_id);
		}
    }
}

/**** /Misc ****/

/**** B2 API ****/


# note: the b2 API currently consists of the Blogger API,
#       plus the following methods:
#
# b2.newPost , b2.getCategories

# Note: the b2 API will be replaced by the standard Weblogs.API once the specs are defined.


### b2.newPost ###

$wpnewpost_sig=array(array($xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcBoolean, $xmlrpcString, $xmlrpcString, $xmlrpcString));

$wpnewpost_doc='Adds a post, blogger-api like, +title +category +postdate';

function b2newpost($m) {
    global $wpdb;

	global $xmlrpcerruser; // import user errcode value
	global $blog_ID,$cache_userdata,$tableposts,$use_rss;
	global $post_default_title,$post_default_category;
	global $cafelogID, $sleep_after_edit;
	$err="";


	$username=$m->getParam(2);
	$password=$m->getParam(3);
	$content=$m->getParam(4);
	$title=$m->getParam(6);
	$category=$m->getParam(7);
	$postdate=$m->getParam(8);

	$username = $username->scalarval();
	$password = $password->scalarval();
	$content = $content->scalarval();
	$title = $title->scalarval();
	$post_category = $category->scalarval();
	$postdate = $postdate->scalarval();


	if (user_pass_ok($username,$password)) {

		$userdata = get_userdatabylogin($username);
		$post_author = $userdata->ID;
		$user_level = $userdata->user_level;
		if ($user_level < 1) {
			return new xmlrpcresp(0, $xmlrpcerruser+1, // user error 1
	   "Sorry, level 0 users can not post");
		}


		$post_content = format_to_post($content);
		$post_title = addslashes($title);


		if ($postdate != "") {
			$post_date = $postdate;
			$post_date_gmt = get_gmt_from_date($postdate);
		} else {
			$post_date = current_time('mysql');
			$post_date_gmt = current_time('mysql', 1);
		}

		$post_data = compact('post_content','post_title','post_date','post_date_gmt','post_author','post_category');
		
		$result = wp_insert_post($post_data);

		if (!$result)
			return new xmlrpcresp(0, $xmlrpcerruser+2, // user error 2
	   "For some strange yet very annoying reason, your entry couldn't be posted.");


		$post_ID = $result;

		if (!isset($blog_ID)) { $blog_ID = 1; }
		
		if (isset($sleep_after_edit) && $sleep_after_edit > 0) {
			sleep($sleep_after_edit);
		}



		pingback($content, $post_ID);


		return new xmlrpcresp(new xmlrpcval("$post_ID"));

	} else {

		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}



### b2.getCategories ###

$wpgetcategories_sig=array(array($xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString));

$wpgetcategories_doc='given a blogID, gives a struct that list categories in that blog, using categoryID and categoryName. categoryName is there so the user would choose a category name from the client, rather than just a number. however, when using b2.newPost, only the category ID number should be sent.';

function b2getcategories($m) {
    global $wpdb;
	global $xmlrpcerruser,$tablecategories;


	$blogid=$m->getParam(0);
	$blogid = $blogid->scalarval(); // we dot not use that yet, that will be used with multiple blogs

	$username=$m->getParam(1);
	$username = $username->scalarval();

	$password=$m->getParam(2);
	$password = $password->scalarval();

	$userdata = get_userdatabylogin($username);


	if (user_pass_ok($username,$password)) {

		$results = $wpdb->get_results("SELECT * FROM $tablecategories ORDER BY cat_ID ASC");
	if (!$results) die("Error getting data");
		$i = 0;
	foreach($results as $row) {
			$cat_name = $row->cat_name;
			$cat_ID = $row->cat_ID;

			$struct[$i] = new xmlrpcval(array("categoryID" => new xmlrpcval($cat_ID),
										  "categoryName" => new xmlrpcval($cat_name)
										  ),"struct");
			$i = $i + 1;
		}

		$data = array($struct[0]);
		for ($j=1; $j<$i; $j++) {
			array_push($data, $struct[$j]);
		}

		$resp = new xmlrpcval($data, "array");

		return new xmlrpcresp($resp);

	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}



### b2.getPostURL ###

$wp_getPostURL_sig = array(array($xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString));

$wp_getPostURL_doc = 'Given a blog ID, username, password, and a post ID, returns the URL to that post.';

function b2_getPostURL($m) {
    global $wpdb;
	global $xmlrpcerruser, $tableposts;
	global $querystring_start, $querystring_equal, $querystring_separator;


	// ideally, this would be used:
	// $blog_ID = $m->getParam(0);
	// $blog_ID = $blog_ID->scalarval();
	// but right now, b2 handles only one blog, so... :P
	$blog_ID = 1;

	$username=$m->getParam(2);
	$username = $username->scalarval();

	$password=$m->getParam(3);
	$password = $password->scalarval();

	$post_ID = $m->getParam(4);
	$post_ID = intval($post_ID->scalarval());

	$userdata = get_userdatabylogin($username);

	if ($userdata->user_level < 1) {
		return new xmlrpcresp(0, $xmlrpcerruser+1, // user error 1
	   "Sorry, users whose level is zero, can not use this method.");
	}

	if (user_pass_ok($username,$password)) {

		$blog_URL = get_settings('home') .'/' . get_settings('blogfilename');

		$postdata = get_postdata($post_ID);

		if (!($postdata===false)) {

			$title = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $postdata['Title']);

			// this code is blatantly derived from permalink_link()
			$archive_mode = get_settings('archive_mode');
			switch($archive_mode) {
				case 'daily':
					$post_URL = $blog_URL.$querystring_start.'m'.$querystring_equal.substr($postdata['Date'],0,4).substr($postdata['Date'],5,2).substr($postdata['Date'],8,2).'#'.$title;
					break;
				case 'monthly':
					$post_URL = $blog_URL.$querystring_start.'m'.$querystring_equal.substr($postdata['Date'],0,4).substr($postdata['Date'],5,2).'#'.$title;
					break;
				case 'weekly':
					if((!isset($cacheweekly)) || (empty($cacheweekly[$postdata['Date']]))) {
						$sql = "SELECT WEEK('".$postdata['Date']."') as wk";
			$row = $wpdb->get_row($sql);
						$cacheweekly[$postdata['Date']] = $row->wk;
					}
					$post_URL = $blog_URL.$querystring_start.'m'.$querystring_equal.substr($postdata['Date'],0,4).$querystring_separator.'w'.$querystring_equal.$cacheweekly[$postdata['Date']].'#'.$title;
					break;
				case 'postbypost':
					$post_URL = $blog_URL.$querystring_start.'p'.$querystring_equal.$post_ID;
					break;
			}
		} else {
			$err = 'This post ID ('.$post_ID.') does not correspond to any post here.';
		}

		if ($err) {
			return new xmlrpcresp(0, $xmlrpcerruser, $err);
		} else {
			return new xmlrpcresp(new xmlrpcval($post_URL));;
		}

	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}

}

/**** /B2 API ****/



/**** Blogger API ****/

# as described on http://plant.blogger.com/api and in various messages in http://groups.yahoo.com/group/bloggerDev/
#
# another list of these methods is there http://www.tswoam.co.uk/blogger_method_listing.html
# so you won't have to browse the eGroup to find all the methods
#
# special note: Evan please keep _your_ API page up to date :p



### blogger.newPost ###

$bloggernewpost_sig=array(array($xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcBoolean));

$bloggernewpost_doc='Adds a post, blogger-api like';

function bloggernewpost($m) {
    global $wpdb;

	global $xmlrpcerruser; // import user errcode value
	global $blog_ID,$cache_userdata,$tableposts,$use_rss;
	global $post_default_title,$post_default_category;
	global $cafelogID, $sleep_after_edit;
	$err="";


	$username=$m->getParam(2);
	$password=$m->getParam(3);
	$content=$m->getParam(4);
	$publish=$m->getParam(5);

	$username = $username->scalarval();
	$password = $password->scalarval();
	$content = $content->scalarval();
	// publish flag sets post status appropriately
	$post_status = $publish->scalarval()?'publish':'draft';
	
	if (user_pass_ok($username,$password)) {

		$userdata = get_userdatabylogin($username);
		$post_author = $userdata->ID;
		$user_level = $userdata->user_level;
		if ($user_level < 1) {
			return new xmlrpcresp(0, $xmlrpcerruser+1, // user error 1
	   "Sorry, level 0 users can not post");
		}

		$post_title = addslashes(xmlrpc_getposttitle($content));
		$post_category = xmlrpc_getpostcategory($content);

		$content = xmlrpc_removepostdata($content);
		$post_content = format_to_post($content);

		$post_date = current_time('mysql');
		$post_date_gmt = current_time('mysql', 1);
		
		$postdata = compact('post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_category', 'post_status');

		$post_ID = wp_insert_post($postdata);

		if (!$post_ID)
			return new xmlrpcresp(0, $xmlrpcerruser+2, // user error 2
	   "For some strange yet very annoying reason, your entry couldn't be posted.");

		if (!isset($blog_ID)) { $blog_ID = 1; }
		
		if (isset($sleep_after_edit) && $sleep_after_edit > 0) {
			sleep($sleep_after_edit);
		}


		pingback($content, $post_ID);

		logIO("O","Posted ! ID: $post_ID");
		return new xmlrpcresp(new xmlrpcval("$post_ID"));

	} else {
		logIO("O","Wrong username/password combination <b>$username / $password</b>");
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}



### blogger.editPost ###

$bloggereditpost_sig=array(array($xmlrpcBoolean, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcBoolean));

$bloggereditpost_doc='Edits a post, blogger-api like';

function bloggereditpost($m) {
    global $wpdb;

	global $xmlrpcerruser; // import user errcode value
	global $blog_ID,$cache_userdata,$tableposts,$use_rss;
	global $post_default_title,$post_default_category, $sleep_after_edit;
	$err="";


	$post_ID=$m->getParam(1);
	$username=$m->getParam(2);
	$password=$m->getParam(3);
	$newcontent=$m->getParam(4);
	$publish=$m->getParam(5);

	$ID = $post_ID->scalarval();
	$username = $username->scalarval();
	$password = $password->scalarval();
	$newcontent = $newcontent->scalarval();
	$post_status = $publish->scalarval()?'publish':'draft';

	$result = wp_get_single_post($ID,ARRAY_A);

	if (!$result)
		return new xmlrpcresp(0, $xmlrpcerruser+2, // user error 2
	  "No such post '$ID'.");

	$userdata = get_userdatabylogin($username);
	$user_ID = $userdata->ID;
	$user_level = $userdata->user_level;

	$postdata=get_postdata($ID);
	$post_authordata=get_userdata($postdata["Author_ID"]);
	$post_author_ID=$postdata["Author_ID"];

	if (($user_ID != $post_author_ID) && ($user_level <= $post_authordata->user_level)) {
			return new xmlrpcresp(0, $xmlrpcerruser+1, // user error 1
	   "Sorry, you do not have the right to edit this post");
	}

	if (user_pass_ok($username,$password)) {

		if ($user_level < 1) {
			return new xmlrpcresp(0, $xmlrpcerruser+1, // user error 1
	   "Sorry, level 0 users can not edit posts");
		}
		
		extract($result);

		$content = $newcontent;

		$post_title = xmlrpc_getposttitle($content);
		$post_category = xmlrpc_getpostcategory($content);

		$content = xmlrpc_removepostdata($content);
		$post_content = format_to_post($content);
		
		$postdata = compact('ID','post_content','post_title','post_category','post_status','post_excerpt');

		$result = wp_update_post($postdata);

		if (!$result)
			return new xmlrpcresp(0, $xmlrpcerruser+2, // user error 2
	   "For some strange yet very annoying reason, the entry couldn't be edited.");

		if (!isset($blog_ID)) { $blog_ID = 1; }
		
		if (isset($sleep_after_edit) && $sleep_after_edit > 0) {
			sleep($sleep_after_edit);
		}



		return new xmlrpcresp(new xmlrpcval(true, "boolean"));

	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}



### blogger.deletePost ###

$bloggerdeletepost_sig=array(array($xmlrpcBoolean, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcBoolean));

$bloggerdeletepost_doc='Deletes a post, blogger-api like';

function bloggerdeletepost($m) {
    global $wpdb;

	global $xmlrpcerruser; // import user errcode value
	global $blog_ID,$cache_userdata,$tableposts,$use_rss;
	global $post_default_title,$post_default_category, $sleep_after_edit;
	$err="";


	$post_ID=$m->getParam(1);
	$username=$m->getParam(2);
	$password=$m->getParam(3);
	$newcontent=$m->getParam(4);

	$post_ID = $post_ID->scalarval();
	$username = $username->scalarval();
	$password = $password->scalarval();
	$newcontent = $newcontent->scalarval();

	$sql = "SELECT * FROM $tableposts WHERE ID = '$post_ID'";
    $result = $wpdb->get_results($sql);
	if (!$result)
		return new xmlrpcresp(0, $xmlrpcerruser+2, // user error 2
	  "No such post '$post_ID'.");

	$userdata = get_userdatabylogin($username);
	$user_ID = $userdata->ID;
	$user_level = $userdata->user_level;

	$postdata=get_postdata($post_ID);
	$post_authordata=get_userdata($postdata["Author_ID"]);
	$post_author_ID=$postdata["Author_ID"];

	if (($user_ID != $post_author_ID) && ($user_level <= $post_authordata->user_level)) {
			return new xmlrpcresp(0, $xmlrpcerruser+1, // user error 1
	   "Sorry, you do not have the right to delete this post");
	}

	if (user_pass_ok($username,$password)) {

		if ($user_level < 1) {
			return new xmlrpcresp(0, $xmlrpcerruser+1, // user error 1
	   "Sorry, level 0 users can not delete posts");
		}

		$result = wp_delete_post($post_ID);

		if (!$result)
			return new xmlrpcresp(0, $xmlrpcerruser+2, // user error 2
	   "For some strange yet very annoying reason, the entry couldn't be deleted.");

		if (!isset($blog_ID)) { $blog_ID = 1; }
		
		if (isset($sleep_after_edit) && $sleep_after_edit > 0) {
			sleep($sleep_after_edit);
		}



		return new xmlrpcresp(new xmlrpcval(true,'boolean'));

	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}



### blogger.getUsersBlogs ###

$bloggergetusersblogs_sig=array(array($xmlrpcArray, $xmlrpcString, $xmlrpcString, $xmlrpcString));

$bloggergetusersblogs_doc='returns the user\'s blogs - this is a dummy function, just so that BlogBuddy and other blogs-retrieving apps work';

function bloggergetusersblogs($m) {
    global $wpdb;
	// this function will have a real purpose with CafeLog's multiple blogs capability

	global $xmlrpcerruser;
	global $tableusers;

	$user_login = $m->getParam(1);
	$user_login = $user_login->scalarval();


	$sql = "SELECT user_level FROM $tableusers WHERE user_login = '$user_login' AND user_level > 3";
    $result = $wpdb->get_results($sql);


	$is_admin = $wpdb->num_rows;

	$struct = new xmlrpcval(array("isAdmin" => new xmlrpcval($is_admin,"boolean"),
									"url" => new xmlrpcval(get_settings('home') .'/'.get_settings('blogfilename')),
									"blogid" => new xmlrpcval("1"),
									"blogName" => new xmlrpcval(get_settings('blogname'))
									),"struct");
    $resp = new xmlrpcval(array($struct), "array");

	return new xmlrpcresp($resp);
}



### blogger.getUserInfo ###

$bloggergetuserinfo_sig=array(array($xmlrpcStruct, $xmlrpcString, $xmlrpcString, $xmlrpcString));

$bloggergetuserinfo_doc='gives the info about a user';

function bloggergetuserinfo($m) {
	global $xmlrpcerruser,$tableusers;


	$username=$m->getParam(1);
	$username = $username->scalarval();

	$password=$m->getParam(2);
	$password = $password->scalarval();

	$userdata = get_userdatabylogin($username);

	if (user_pass_ok($username,$password)) {
		$struct = new xmlrpcval(array("nickname" => new xmlrpcval($userdata->user_nickname),
									  "userid" => new xmlrpcval($userdata->ID),
									  "url" => new xmlrpcval($userdata->user_url),
									  "email" => new xmlrpcval($userdata->user_email),
									  "lastname" => new xmlrpcval($userdata->user_lastname),
									  "firstname" => new xmlrpcval($userdata->user_firstname)
									  ),"struct");
		$resp = $struct;
		return new xmlrpcresp($resp);

	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}



### blogger.getPost ###

$bloggergetpost_sig=array(array($xmlrpcStruct, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString));

$bloggergetpost_doc='fetches a post, blogger-api like';

function bloggergetpost($m) {
	global $xmlrpcerruser,$tableposts;


	$post_ID=$m->getParam(1);
	$post_ID = $post_ID->scalarval();

	$username=$m->getParam(2);
	$username = $username->scalarval();

	$password=$m->getParam(3);
	$password = $password->scalarval();

	if (user_pass_ok($username,$password)) {
		$postdata = get_postdata($post_ID);

		if ($postdata["Date"] != "") {
			$post_date = mysql2date("Ymd\TH:i:s", $postdata['Date']);

			$content  = "<title>".stripslashes($postdata["Title"])."</title>";
			$content .= "<category>".$postdata["Category"]."</category>";
			$content .= stripslashes($postdata["Content"]);

			$struct = new xmlrpcval(array("userid" => new xmlrpcval($postdata["Author_ID"]),
										  "dateCreated" => new xmlrpcval($post_date,"dateTime.iso8601"),
										  "content" => new xmlrpcval($content),
										  "postid" => new xmlrpcval($postdata["ID"])
										  ),"struct");

			$resp = $struct;
			return new xmlrpcresp($resp);
		} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 4
	   "No such post #$post_ID");
		}
	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}



### blogger.getRecentPosts ###

$bloggergetrecentposts_sig=array(array($xmlrpcArray, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcInt));

$bloggergetrecentposts_doc='fetches X most recent posts, blogger-api like';

function bloggergetrecentposts($m) {
    global $wpdb;
	global $xmlrpcerruser,$tableposts;

	error_reporting(0); // there is a bug in phpxmlrpc that makes it say there are errors while the output is actually valid, so let's disable errors for that function


	$blogid = 1;	// we don't need that yet

	$numposts=$m->getParam(4);
	$numposts = $numposts->scalarval();

	if ($numposts > 0) {
		$limit = " LIMIT $numposts";
	} else {
		$limit = "";
	}

	$username=$m->getParam(2);
	$username = $username->scalarval();

	$password=$m->getParam(3);
	$password = $password->scalarval();

	if (user_pass_ok($username,$password)) {

		$sql = "SELECT * FROM $tableposts ORDER BY post_date DESC".$limit;
		$result = $wpdb->get_results($sql);
		if (!$result)
			return new xmlrpcresp(0, $xmlrpcerruser+2, // user error 2
	   "For some strange yet very annoying reason, the entries couldn't be fetched.");

		$data = new xmlrpcval("","array");

		$i = 0;
		foreach ($result as $row) {
			$postdata = array(
				"ID" => $row->ID,
				"Author_ID" => $row->post_author,
				"Date" => $row->post_date,
				"Content" => $row->post_content,
				"Title" => $row->post_title,
				"Category" => $row->post_category
			);

			$post_date = mysql2date("Ymd\TH:i:s", $postdata['Date']);

			$content  = "<title>".stripslashes($postdata["Title"])."</title>";
			$content .= "<category>".get_cat_name($postdata["Category"])."</category>";
			$content .= stripslashes($postdata["Content"]);

//			$content = convert_chars($content,"html");
//			$content = $postdata["Title"];

			$category = new xmlrpcval($postdata['Category']);

			$authordata = get_userdata($postdata["Author_ID"]);
			switch($authordata["user_idmode"]) {
				case "nickname":
					$authorname = $authordata["user_nickname"];

			case "login":
					$authorname = $authordata["user_login"];
					break;
			case "firstname":
					$authorname = $authordata["user_firstname"];
					break;
			case "lastname":
					$authorname = $authordata["user_lastname"];
					break;
			case "namefl":
					$authorname = $authordata["user_firstname"]." ".$authordata["user_lastname"];
					break;
			case "namelf":
					$authorname = $authordata["user_lastname"]." ".$authordata["user_firstname"];
					break;
			default:
					$authorname = $authordata["user_nickname"];
					break;
			}

			$struct[$i] = new xmlrpcval(array("authorName" => new xmlrpcval($authorname),
										"userid" => new xmlrpcval($postdata["Author_ID"]),
										"dateCreated" => new xmlrpcval($post_date,"dateTime.iso8601"),
										"content" => new xmlrpcval($content),
										"postid" => new xmlrpcval($postdata["ID"]),
										'category' => $category
										),"struct");
			$i = $i + 1;
		}

		$data = array($struct[0]);
		for ($j=1; $j<$i; $j++) {
			array_push($data, $struct[$j]);
		}

		$resp = new xmlrpcval($data, "array");

		return new xmlrpcresp($resp);

	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}



### blogger.getTemplate ###

# note: on b2, it fetches your $blogfilename, or b2.php if you didn't specify the variable

$bloggergettemplate_sig=array(array($xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString));

$bloggergettemplate_doc='returns the default template file\'s code';

function bloggergettemplate($m) {
	global $xmlrpcerruser,$tableusers;

	error_reporting(0); // there is a bug in phpxmlrpc that makes it say there are errors while the output is actually valid, so let's disable errors for that function


	$blogid = 1;	// we do not need this yet

	$templateType=$m->getParam(4);
	$templateType = $templateType->scalarval();

	$username=$m->getParam(2);
	$username = $username->scalarval();

	$password=$m->getParam(3);
	$password = $password->scalarval();

	$userdata = get_userdatabylogin($username);

	if ($userdata->user_level < 3) {
		return new xmlrpcresp(0, $xmlrpcerruser+1, // user error 1
	   "Sorry, users whose level is less than 3, can not edit the template.");
	}

	if (user_pass_ok($username,$password)) {

	if ($templateType == "main") {
		if (get_settings('blogfilename') != '') {
			$file = get_settings('blogfilename');
		} else {
			$file = "wp.php";
		}
	} elseif ($templateType == "archiveIndex") {
		$file = "wp.php";
	}

	$f = fopen($file,"r");
	$content = fread($f,filesize($file));
	fclose($file);

	$content = str_replace("\n","\r\n",$content);	// so it is actually editable with a windows/mac client, instead of being returned as a looooooooooong line of code

	return new xmlrpcresp(new xmlrpcval("$content"));

	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}



### blogger.setTemplate ###

# note: on b2, it saves that in your $blogfilename, or b2.php if you didn't specify the variable

$bloggersettemplate_sig=array(array($xmlrpcBoolean, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcString));

$bloggersettemplate_doc='saves the default template file\'s code';

function bloggersettemplate($m) {
	global $xmlrpcerruser, $tableusers;

	error_reporting(0); // there is a bug in phpxmlrpc that makes it say there are errors while the output is actually valid, so let's disable errors for that function


	$blogid = 1;	// we do not need this yet

	$template=$m->getParam(4);
	$template = $template->scalarval();

	$templateType=$m->getParam(5);
	$templateType = $templateType->scalarval();

	$username=$m->getParam(2);
	$username = $username->scalarval();

	$password=$m->getParam(3);
	$password = $password->scalarval();

	$userdata = get_userdatabylogin($username);

	if ($userdata->user_level < 3) {
		return new xmlrpcresp(0, $xmlrpcerruser+1, // user error 1
	   "Sorry, users whose level is less than 3, can not edit the template.");
	}

	if (user_pass_ok($username,$password)) {

	if ($templateType == 'main') {
		if (get_settings('blogfilename') != '') {
			$file = get_settings('blogfilename');
		} else {
			$file = "wp.php";
		}
	} elseif ($templateType == "archiveIndex") {
		$file = "wp.php";
	}

	$f = fopen($file,"w+");
	fwrite($f, $template);
	fclose($file);

	return new xmlrpcresp(new xmlrpcval(true, "boolean"));

	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}

/**** /Blogger API ****/



/**** metaWeblog API ****/

/**********************
 *
 * metaWeblog API extensions
 * added by 
 *  Dougal Campbell <dougal@gunters.org> 
 *  http://dougal.gunters.org/
 *
 **********************/

$mwnewpost_sig =  array(array($xmlrpcString,$xmlrpcString,$xmlrpcString,$xmlrpcString,$xmlrpcStruct,$xmlrpcBoolean));
$mwnewpost_doc = 'Add a post, MetaWeblog API-style';

function mwnewpost($params) {
	global $xmlrpcerruser;
	global $blog_ID, $cache_userdata,$tableposts;
	global $use_rss, $post_default_title;
	global $post_default_category,$cafelogID,$sleep_after_edit;

	$xblogid = $params->getParam(0);
	$xuser = $params->getParam(1);
	$xpass = $params->getParam(2);
	$xcontent = $params->getParam(3);
	$xpublish = $params->getParam(4);
	
	$blogid = $xblogid->scalarval();
	$username = $xuser->scalarval();
	$password = $xpass->scalarval();
	$contentstruct = phpxmlrpc_decode($xcontent);
	$post_status = $xpublish->scalarval()?'publish':'draft';

	// Check login
	if (user_pass_ok($username,$password)) {
		$userdata = get_userdatabylogin($username);
		$post_author = $userdata->ID;
		$user_level = $userdata->user_level;
		if ($user_level < 1) {
			return new xmlrpcresp(0, $xmlrpcerruser+1,
			  "Sorry, level 0 users cannot post");
		}


		$post_title = $contentstruct['title'];
		$post_content = format_to_post($contentstruct['description']);

		$post_excerpt = $contentstruct['mt_excerpt'];
		$post_more = $contentstruct['mt_text_more'];

		$comment_status = $contentstruct['mt_allow_comments']?'open':'closed';
		$ping_status = $contentstruct['mt_allow_pings']?'open':'closed';

		if ($post_more) {
			$post_content = $post_content . "\n<!--more-->\n" . $post_more;
		}
		
		// Do some timestamp voodoo
		$dateCreated = $contentstruct['dateCreated'];
		$dateCreated = $dateCreated ? iso8601_decode($dateCreated) : current_time('timestamp',1);
		$post_date = gmdate('Y-m-d H:i:s', $dateCreated + get_settings('gmt_offset') * 3600);
		$post_date_gmt = get_gmt_from_date(date('Y-m-d H:i:s', $dateCreated));
		
		$catnames = $contentstruct['categories'];
logio("O","Post cats: " . print_r($catnames));
		$post_category = array();
		if ($catnames) {
			foreach ($catnames as $cat) {
				$post_category[] = get_cat_ID($cat);
			}
		} else {
			$post_category[] = 1;
		}
		
		// We've got all the data -- post it:
		$postarr = compact('post_author','post_date','post_date_gmt','post_content','post_title','post_category','post_status','post_excerpt','comment_status','ping_status');

		$post_ID = wp_insert_post($postarr);
		
		if (!$post_ID) {
			return new xmlrpcresp(0, $xmlrpcerruser+2, "For some strange yet very annoying reason, your entry could not be posted.");
		}

		if (!isset($blog_ID)) { $blog_ID = 1; }

		if (isset($sleep_after_edit) && $sleep_after_edit > 0) {
			sleep($sleep_after_edit);
		}

		pingback($content, $post_ID);
		trackback_url_list($content_struct['mt_tb_ping_urls'],$post_ID);

		logIO("O","(MW) Posted ! ID: $post_ID");
		$myResp = new xmlrpcval($post_ID,"string");

		return new xmlrpcresp($myResp);

	} else {
		logIO("O","(MW) Wrong username/password combination <b>$username / $password</b>");
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}

$mweditpost_sig =  array(array($xmlrpcBoolean,$xmlrpcString,$xmlrpcString,$xmlrpcString,$xmlrpcStruct,$xmlrpcBoolean));
$mweditpost_doc = 'Edit a post, MetaWeblog API-style';

function mweditpost ($params) {	// ($postid, $user, $pass, $content, $publish) 
	global $xmlrpcerruser;

	$xpostid = $params->getParam(0);
	$xuser = $params->getParam(1);
	$xpass = $params->getParam(2);
	$xcontent = $params->getParam(3);
	$xpublish = $params->getParam(4);
	
	$ID = $xpostid->scalarval();
	$username = $xuser->scalarval();
	$password = $xpass->scalarval();
	$contentstruct = phpxmlrpc_decode($xcontent);
	$postdata = wp_get_single_post($ID,ARRAY_A);

	if (!$postdata)
		return new xmlrpcresp(0, $xmlrpcerruser+2, // user error 2
			"No such post $ID.");
			
	$userdata = get_userdatabylogin($username);
	$user_ID = $userdata->ID;
	$user_level = $userdata->user_level;
	$time_difference = get_settings('gmt_offset');
	
	$post_author_ID = $postdata['post_author'];
	$post_authordata = get_userdata($post_author_ID);

	if (($user_ID != $post_author_ID) && ($user_level <= $post_authordata->user_level)) {
		return new xmlrpcresp(0, $xmlrpcerruser+1, // user error 1
			"Sorry, you do not have the right to edit this post.");
	}
		
	// Check login
	if (user_pass_ok($username,$password)) {
		if ($user_level < 1) {
			return new xmlrpcresp(0, $xmlrpcerruser+1,

			  "Sorry, level 0 users cannot edit posts");
		}

		extract($postdata);

		$post_title = $contentstruct['title'];
		$post_content = format_to_post($contentstruct['description']);
		$catnames = $contentstruct['categories'];
		
		if ($catnames) {
			foreach ($catnames as $cat) {
				$post_category[] = get_cat_ID($cat);
			}
		}

		$post_excerpt = $contentstruct['mt_excerpt'];
		$post_more = $contentstruct['mt_text_more'];
		$post_status = $xpublish->scalarval()?'publish':'draft';
		if ($post_more) {
			$post_content = $post_content . "\n<!--more-->\n" . $post_more;
		}
		$comment_status = (1 == $contentstruct['mt_allow_comments'])?'open':'closed';
		$ping_status = $contentstruct['mt_allow_pings']?'open':'closed';

		// Do some timestamp voodoo
		$dateCreated = $contentstruct['dateCreated'];
		$dateCreated = $dateCreated ? iso8601_decode($dateCreated) : current_time('timestamp',1);
		$post_date = date('Y-m-d H:i:s', $dateCreated);
		$post_date_gmt = get_gmt_from_date($post_date);


		// We've got all the data -- post it:
		$newpost = compact('ID','post_content','post_title','post_category','post_status','post_excerpt','comment_status','ping_status','post_date','post_date_gmt');

		$newpost_ID = wp_update_post($newpost);
		
		if (!$newpost_ID) {
			return new xmlrpcresp(0, $xmlrpcerruser+2, "For some strange yet very annoying reason, your entry could not be posted.");
		}

		if (!isset($blog_ID)) { $blog_ID = 1; }

		if (isset($sleep_after_edit) && $sleep_after_edit > 0) {
			sleep($sleep_after_edit);
		}

		pingback($content, $post_ID);
		trackback_url_list($content_struct['mt_tb_ping_urls'],$post_ID);

		logIO("O","(MW) Edited ! ID: $post_ID");
		$myResp = new xmlrpcval(true,"boolean");

		return new xmlrpcresp($myResp);

	} else {
		logIO("O","(MW) Wrong username/password combination <b>$username / $password</b>");
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}

$mwgetpost_sig =  array(array($xmlrpcStruct,$xmlrpcString,$xmlrpcString,$xmlrpcString));
$mwegetpost_doc = 'Get a post, MetaWeblog API-style';

function mwgetpost ($params) {	// ($postid, $user, $pass) 
	global $xmlrpcerruser;
	
	$xpostid = $params->getParam(0);
	$xuser = $params->getParam(1);
	$xpass = $params->getParam(2);
	
	$post_ID = $xpostid->scalarval();
	$username = $xuser->scalarval();
	$password = $xpass->scalarval();

	// Check login
	if (user_pass_ok($username,$password)) {
		$postdata = get_postdata($post_ID);

		if ($postdata["Date"] != "") {

			$post_date = mysql2date('Ymd\TH:i:s', $postdata['Date']);
			
			$catids = wp_get_post_cats($post_ID);
			foreach($catids as $catid) {
				$catname = get_cat_name($catid);
				$catnameenc = new xmlrpcval($catname);
				$catlist[] = $catnameenc;
			}			
			$post = get_extended($postdata['Content']);
			$allow_comments = ('open' == $postdata['comment_status'])?1:0;
			$allow_pings = ('open' == $postdata['ping_status'])?1:0;

			$resp = array(
				'link' => new xmlrpcval(post_permalink($post_ID)),
				'title' => new xmlrpcval($postdata["Title"]),
				'description' => new xmlrpcval($post['main']),
				'dateCreated' => new xmlrpcval($post_date,'dateTime.iso8601'),
				'userid' => new xmlrpcval($postdata["Author_ID"]),
				'postid' => new xmlrpcval($postdata["ID"]),
				'content' => new xmlrpcval($postdata["Content"]),
				'permalink' => new xmlrpcval(post_permalink($post_ID)),
				'categories' => new xmlrpcval($catlist,'array'),
				'mt_excerpt' => new xmlrpcval($postdata['Excerpt']),
				'mt_allow_comments' => new xmlrpcval($allow_comments,'int'),
				'mt_allow_pings' => new xmlrpcval($allow_pings,'int'),
				'mt_text_more' => new xmlrpcval($post['extended'])
			);
			
			$resp = new xmlrpcval($resp,'struct');
			
			return new xmlrpcresp($resp);
		} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 4
			"No such post #$post_ID");
		}
	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}

}

$mwrecentposts_sig =  array(array($xmlrpcArray,$xmlrpcString,$xmlrpcString,$xmlrpcString,$xmlrpcInt));
$mwerecentposts_doc = 'Get recent posts, MetaWeblog API-style';

function mwrecentposts ($params) {	// ($blogid, $user, $pass, $num) 
	global $xmlrpcerruser;

	$xblogid = $params->getParam(0);
	$xuser = $params->getParam(1);
	$xpass = $params->getParam(2);
	$xnum = $params->getParam(3);
	
	$blogid = $xblogid->scalarval();
	$username = $xuser->scalarval();
	$password = $xpass->scalarval();
	$num = $xnum->scalarval();

	// Check login
	if (user_pass_ok($username,$password)) {

		$postlist = wp_get_recent_posts($num);
		
		// Build response packet. We can't just use xmlrpc_encode,
		// because of the dateCreated field, which must be a date type.
		
		// Encode each entry of the array.
		foreach($postlist as $entry) {

			$isoString = mysql2date('Ymd\TH:i:s', $entry['post_date']);
			$date = new xmlrpcval($isoString,"dateTime.iso8601");
			$userid = new xmlrpcval($entry['post_author']);
			$content = new xmlrpcval($entry['post_content']);
			$excerpt = new xmlrpcval($entry['post_excerpt']);
			
			$pcat = stripslashes(get_cat_name($entry['post_category']));
			
			// For multiple cats, we might do something like
			// this in the future:
			//$catstruct['description'] = $pcat;
			//$catstruct['categoryId'] = $entry['post_category'];
			//$catstruct['categoryName'] = $pcat;
			//$catstruct['isPrimary'] = TRUE;
			
			//$catstruct2 = phpxmlrpc_encode($catstruct);
			
			$categories = new xmlrpcval(array(new xmlrpcval($pcat)),'array');

			$post = get_extended($entry['post_content']);

			$postid = new xmlrpcval($entry['ID']);
			$title = new xmlrpcval(stripslashes($entry['post_title']));
			$description = new xmlrpcval(stripslashes($post['main']));
			$link = new xmlrpcval(post_permalink($entry['ID']));
			$permalink = $link;

			$extended = new xmlrpcval(stripslashes($post['extended']));

			$allow_comments = new xmlrpcval((('open' == $entry['comment_status'])?1:0),'int');
			$allow_pings = new xmlrpcval((('open' == $entry['ping_status'])?1:0),'int');

			$encode_arr = array(
				'dateCreated' => $date,
				'userid' => $userid,
				'postid' => $postid,
				'categories' => $categories,
				'title' => $title,
				'description' => $description,
				'link' => $link,
				'permalink' => $permalink,
				'mt_excerpt' => $excerpt,
				'mt_allow_comments' => $allow_comments,
				'mt_allow_pings' => $allow_pings,
				'mt_text_more' => $extended
			);
			
			$xmlrpcpostarr[] = new xmlrpcval($encode_arr,"struct");
		}	

		// Now convert that to an xmlrpc array type
		$myResp = new xmlrpcval($xmlrpcpostarr,"array");

		return new xmlrpcresp($myResp);
	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}


$mwgetcats_sig =  array(array($xmlrpcArray,$xmlrpcString,$xmlrpcString,$xmlrpcString));
$mwgetcats_doc = 'Get a post, MetaWeblog API-style';

function mwgetcats ($params) {	// ($blogid, $user, $pass) 
	global $xmlrpcerruser,$wpdb,$tablecategories;
	global $querystring_start, $querystring_equal, $querystring_separator;
	
	$blog_URL = get_settings('home') . '/' . get_settings('blogfilename');
	
	$arr = array();

	if ($cats = $wpdb->get_results("SELECT cat_ID,cat_name FROM $tablecategories",ARRAY_A)) {
		foreach ($cats as $cat) {
			$struct['categoryId'] = $cat['cat_ID'];
			$struct['description'] = $cat['cat_name'];
			$struct['categoryName'] = $cat['cat_name'];
			$struct['htmlUrl'] = htmlspecialchars($blog_URL . $querystring_start . 'cat' . $querystring_equal . $cat['cat_ID']);
			$struct['rssUrl'] = ''; // will probably hack alexking's stuff in here
			
			$arr[] = phpxmlrpc_encode($struct);
		}
	}
	
	$resp = new xmlrpcval($arr,'array');

	return new xmlrpcresp($resp);
}


$mwnewmedia_sig =  array(array($xmlrpcStruct,$xmlrpcString,$xmlrpcString,$xmlrpcString,$xmlrpcStruct));
$mwnewmedia_doc = 'Upload image or other binary data, MetaWeblog API-style (unimplemented)';

function mwnewmedia($params) {	// ($blogid, $user, $pass, $struct) 
	global $xmlrpcerruser;
	
	return new xmlrpcresp(0, $xmlrpcerruser+10, // user error 10
	  'metaWeblog.newMediaObject not implemented (yet)');
}


/**** /MetaWeblog API ****/


/**** MovableType API ****/

/**********************
 *
 * MovableType API extensions
 * added by 
 *  Dougal Campbell <dougal@gunters.org> 
 *  http://dougal.gunters.org/
 *
 * DONE:
 *  mt.getCategoryList
 *  mt.setPostCategories
 *  mt.supportedMethods
 *  mt.getPostCategories
 *  mt.publishPost
 *  mt.getRecentPostTitles
 *  extend metaWeblog.newPost
 *  extend metaWeblog.editPost
 *  extend metaWeblog.getPost
 *  extend metaWeblog.getRecentPosts
 *
 * PARTIALLY DONE:
 *  mt.supportedTextFilters		// empty stub, because WP doesn't support per-post text filters at this time
 *  mt.getTrackbackPings		// another stub.
 *  metaWeblog.newMediaObject	// ditto. For now.
 *
 **********************/
 
$mt_supportedMethods_sig = array(array($xmlrpcArray));
$mt_supportedMethods_doc = 'Retrieve information about the XML-RPC methods supported by the server.';

// ripped out of system.listMethods
function mt_supportedMethods($params) {
    global $dispatch_map, $xmlrpcerr, $xmlrpcstr, $_xmlrpcs_dmap;
    $v=new xmlrpcval();
    $dmap=$dispatch_map;
    $outAr=array();
    for(reset($dmap); list($key, $val)=each($dmap); ) {
	$outAr[]=new xmlrpcval($key, "string");
    }
    $dmap=$_xmlrpcs_dmap;
    for(reset($dmap); list($key, $val)=each($dmap); ) {
	$outAr[]=new xmlrpcval($key, "string");
    }
    $v->addArray($outAr);
    return new xmlrpcresp($v);

}

$mt_getPostCategories_sig = array(array($xmlrpcArray, $xmlrpcString, $xmlrpcString, $xmlrpcString));
$mt_getPostCategories_doc = "Returns a list of all categories to which the post is assigned.";

function mt_getPostCategories($params) {
	global $xmlrpcusererr;

	$xpostid = $params->getParam(0);
	$xuser = $params->getParam(1);
	$xpass = $params->getParam(2);
	
	$post_ID = $xpostid->scalarval();
	$username = $xuser->scalarval();
	$password = $xpass->scalarval();

	if (user_pass_ok($username,$password)) {
		$catids = wp_get_post_cats('1', $post_ID);

		// The first category listed will be set as primary
		$struct['isPrimary'] = true;
		foreach($catids as $catid) {	
			$struct['categoryId'] = $catid;
			$struct['categoryName'] = get_cat_name($catid);

			$resp_struct[] = phpxmlrpc_encode($struct);
			$struct['isPrimary'] = false;
		}
		
		// Return an array of structs	
		$resp_array = new xmlrpcval($resp_struct,'array');
		
		return new xmlrpcresp($resp_array);

	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}

$mt_setPostCategories_sig = array(array($xmlrpcBoolean, $xmlrpcString, $xmlrpcString, $xmlrpcString, $xmlrpcArray));
$mt_setPostCategories_doc = "Sets the categories for a post";

function mt_setPostCategories($params) {
	global $xmlrpcusererr;
	
	$xpostid = $params->getParam(0);
	$xuser = $params->getParam(1);
	$xpass = $params->getParam(2);
	$xcats = $params->getParam(3);
	
	$post_ID = $xpostid->scalarval();
	$username = $xuser->scalarval();
	$password = $xpass->scalarval();
	$cats = phpxmlrpc_decode($xcats);
	
	foreach($cats as $cat) {
		$catids[] = $cat['categoryId'];
	}
	
	if (user_pass_ok($username,$password)) {
		wp_set_post_cats('', $post_ID, $catids);
		
		return new xmlrpcresp(new xmlrpcval(true,'boolean'));
	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}

$mt_publishPost_sig = array(array($xmlrpcBoolean, $xmlrpcString, $xmlrpcString, $xmlrpcString));
$mt_publishPost_doc = "Publish (rebuild) all of the static files related to an entry. Equivalent to saving an entry in the system (but without the ping).";

function mt_publishPost($params) {
	global $xmlrpcusererr;
	
	$xpostid = $params->getParam(0);
	$xuser = $params->getParam(1);
	$xpass = $params->getParam(2);
	
	$post_ID = $xpostid->scalarval();
	$username = $xuser->scalarval();
	$password = $xpass->scalarval();

	if (user_pass_ok($username,$password)) {
		$postdata = wp_get_single_post($post_ID,ARRAY_A);
		
		$postdata['post_status'] = 'publish';
		
		// retain old cats
		$cats = wp_get_post_cats('',$post_ID);
		$postdata['post_category'] = $cats;
	
		$result = wp_update_post($postdata);

		return new xmlrpcresp(new xmlrpcval($result,'boolean'));
	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}

$mt_getRecentPostTitles_sig = array(array($xmlrpcArray,$xmlrpcString,$xmlrpcString,$xmlrpcString,$xmlrpcInt));
$mt_getRecentPostTitles_doc = "Returns a bandwidth-friendly list of the most recent posts in the system.";

function mt_getRecentPostTitles($params) {
	global $xmlrpcusererr, $wpdb, $tableposts;

	$xblogid = $params->getParam(0);
	$xuser = $params->getParam(1);
	$xpass = $params->getParam(2);
	$xnumposts = $params->getParam(3);

	$blogid = $xblogid->scalarval();
	$username = $xuser->scalarval();
	$password = $xpass->scalarval();
	$numposts = intval($xnumposts->scalarval());

	if (user_pass_ok($username,$password)) {
		$sql = "SELECT post_date, post_author, ID, post_title FROM $tableposts ORDER BY post_date DESC LIMIT $numposts";
		$posts = $wpdb->get_results($sql,ARRAY_A);
		
		foreach($posts as $post) {

			$post_date = mysql2date('Ymd\TH:i:s', $post['post_date']);

			$struct['dateCreated'] = new xmlrpcval($post_date, 'dateTime.iso8601');
			$struct['userid'] = new xmlrpcval($post['post_author'], 'string');
			$struct['postid'] = new xmlrpcval($post['ID'], 'string');
			$struct['title'] = new xmlrpcval($post['post_title'], 'string');
			
			$result[] = $struct;
		}
		
		return new xmlrpcresp(new xmlrpcval($results,'array'));

	} else {
		return new xmlrpcresp(0, $xmlrpcerruser+3, // user error 3
	   'Wrong username/password combination '.$username.' / '.starify($password));
	}
}


$mt_supportedTextFilters_sig = array(array($xmlrpcArray));
$mt_supportedTextFilters_doc = "Retrieve information about the text formatting plugins supported by the server. (not implemented)";

function mt_supportedTextFilters($params) {
	// This should probably check the status of the 'use_bbcode' 
	// and 'use_gmcode' config options.
	
	return new xmlrpcresp(new xmlrpcval(array(),'array'));
}



$mt_getTrackbackPings_sig = array(array($xmlrpcArray,$xmlrpcString));
$mt_getTrackbackPings_doc = "Retrieve the list of Trackback pings posted to a particular entry. (not implemented)";

function mt_getTrackbackPings($params) {
	$struct['pingTitle'] = '';
	$struct['pingURL'] = '';
	$struct['pingIP'] = '';
	
	$xmlstruct = phpxmlrpc_encode($struct);
	
	return new xmlrpcresp(new xmlrpcval(array($xmlstruct),'array'));
}



/**** /MovableType API ****/


/**** PingBack functions ****/

$pingback_ping_sig = array(array($xmlrpcString, $xmlrpcString, $xmlrpcString));

$pingback_ping_doc = 'Gets a pingback and registers it as a comment prefixed by &lt;pingback /&gt;';

function pingback_ping($m) { // original code by Mort
	// (http://mort.mine.nu:8080)
	global $tableposts, $tablecomments, $wpdb; 
	global $wp_version; 
	global $wpdb;

	    
	if (!get_settings('use_pingback')) {
		return new xmlrpcresp(new xmlrpcval('Sorry, this weblog does not allow you to pingback its posts.'));
	}


	//$log = debug_fopen('./xmlrpc.log', 'w');

	$title='';

	$pagelinkedfrom = $m->getParam(0);
	$pagelinkedfrom = $pagelinkedfrom->scalarval();

	$pagelinkedto = $m->getParam(1);
	$pagelinkedto = $pagelinkedto->scalarval();

	$pagelinkedfrom = str_replace('&amp;', '&', $pagelinkedfrom);
	$pagelinkedto = preg_replace('#&([^amp\;])#is', '&amp;$1', $pagelinkedto);

	//debug_fwrite($log, 'BEGIN '.time().' - '.date('Y-m-d H:i:s')."\n\n");
	//debug_fwrite($log, 'Page linked from: '.$pagelinkedfrom."\n");
	//debug_fwrite($log, 'Page linked to: '.$pagelinkedto."\n");

	$messages = array(
		htmlentities("Pingback from ".$pagelinkedfrom." to "
			. $pagelinkedto . " registered. Keep the web talking! :-)"),
		htmlentities("We can't find the URL to the post you are trying to "
			. "link to in your entry. Please check how you wrote the post's permalink in your entry."),
		htmlentities("We can't find the post you are trying to link to."
			. " Please check the post's permalink.")
	);

	$message = $messages[0];

	// Check if the page linked to is in our site
	$pos1 = strpos($pagelinkedto, str_replace('http://', '', str_replace('www.', '', get_settings('home'))));
	if($pos1) {

		// let's find which post is linked to
		$urltest = parse_url($pagelinkedto);
		if ($post_ID = url_to_postid($pagelinkedto)) {
			$way = 'url_to_postid()';
		}
		elseif (preg_match('#p/[0-9]{1,}#', $urltest['path'], $match)) {
			// the path defines the post_ID (archives/p/XXXX)
			$blah = explode('/', $match[0]);
			$post_ID = $blah[1];
			$way = 'from the path';
		} elseif (preg_match('#p=[0-9]{1,}#', $urltest['query'], $match)) {
			// the querystring defines the post_ID (?p=XXXX)
			$blah = explode('=', $match[0]);
			$post_ID = $blah[1];
			$way = 'from the querystring';
		} elseif (isset($urltest['fragment'])) {
			// an #anchor is there, it's either...
			if (intval($urltest['fragment'])) {
				// ...an integer #XXXX (simpliest case)
				$post_ID = $urltest['fragment'];
				$way = 'from the fragment (numeric)';
			} elseif (preg_match('/post-[0-9]+/',$urltest['fragment'])) {
				// ...a post id in the form 'post-###'
				$post_ID = preg_replace('/[^0-9]+/', '', $urltest['fragment']);
				$way = 'from the fragment (post-###)';
			} elseif (is_string($urltest['fragment'])) {
				// ...or a string #title, a little more complicated
				$title = preg_replace('/[^a-zA-Z0-9]/', '.', $urltest['fragment']);
				$sql = "SELECT ID FROM $tableposts WHERE post_title RLIKE '$title'";
				$post_ID = $wpdb->get_var($sql) or die("Query: $sql\n\nError: ");
				$way = 'from the fragment (title)';
			}
		} else {
			// TODO: Attempt to extract a post ID from the given URL
			$post_ID = -1;
			$way = 'no match';
		}

		logIO("O","(PB) URI='$pagelinkedto' ID='$post_ID' Found='$way'");

		//debug_fwrite($log, "Found post ID $way: $post_ID\n");

		$sql = 'SELECT post_author FROM '.$tableposts.' WHERE ID = '.$post_ID;
		$result = $wpdb->get_results($sql);

		if ($wpdb->num_rows) {

			//debug_fwrite($log, 'Post exists'."\n");

			// Let's check that the remote site didn't already pingback this entry
			$sql = 'SELECT * FROM '.$tablecomments.' 
				WHERE comment_post_ID = '.$post_ID.' 
					AND comment_author_url = \''.$pagelinkedfrom.'\' 
					AND comment_content LIKE \'%<pingback />%\'';
			$result = $wpdb->get_results($sql);
	    
			if ($wpdb->num_rows || (1==1)) {

				// very stupid, but gives time to the 'from' server to publish !
				sleep(1);

				// Let's check the remote site
				$fp = @fopen($pagelinkedfrom, 'r');

				$puntero = 4096;
				while($remote_read = fread($fp, $puntero)) {
					$linea .= $remote_read;
				}
					// Work around bug in strip_tags():
					$linea = str_replace('<!DOCTYPE','<DOCTYPE',$linea);
					$linea = strip_tags($linea, '<title><a>');
					$linea = strip_all_but_one_link($linea, $pagelinkedto);
					// I don't think we need this? -- emc3
					//$linea = preg_replace('#&([^amp\;])#is', '&amp;$1', $linea);
					if (empty($matchtitle)) {
						preg_match('|<title>([^<]*?)</title>|is', $linea, $matchtitle);
					}
					$pos2 = strpos($linea, $pagelinkedto);
					$pos3 = strpos($linea, str_replace('http://www.', 'http://', $pagelinkedto));
					if (is_integer($pos2) || is_integer($pos3)) {
						//debug_fwrite($log, 'The page really links to us :)'."\n");
						$pos4 = (is_integer($pos2)) ? $pos2 : $pos3;
						$start = $pos4-100;
						$context = substr($linea, $start, 250);
						$context = str_replace("\n", ' ', $context);
						$context = str_replace('&amp;', '&', $context);
					} else {
						//debug_fwrite($log, 'The page doesn\'t link to us, here\'s an excerpt :'."\n\n".$linea."\n\n");
					}
				//}
				//debug_fwrite($log, '*****'."\n\n");
				fclose($fp);

				if (!empty($context)) {
					// Check if pings are on, inelegant exit
					$pingstatus = $wpdb->get_var("SELECT ping_status FROM $tableposts WHERE ID = $post_ID");
					if ('closed' == $pingstatus) die('Sorry, pings are turned off for this post.');

					$pagelinkedfrom = preg_replace('#&([^amp\;])#is', '&amp;$1', $pagelinkedfrom);
					$title = (!strlen($matchtitle[1])) ? $pagelinkedfrom : $matchtitle[1];
					$original_context = $context;
					$context = '<pingback />[...] '.addslashes(trim($context)) .' [...]';
					$context = format_to_post($context);
					$original_pagelinkedfrom = $pagelinkedfrom;
					$pagelinkedfrom = addslashes($pagelinkedfrom);
					$original_title = $title;
					$title = addslashes(strip_tags(trim($title)));
					$user_ip = $_SERVER['REMOTE_ADDR'];
					$now = gmdate('Y-m-d H:i:s');
					if(check_comment($title, '', $pagelinkedfrom, $context, $user_ip)) {
						$approved = 1;
					} else {
						$approved = 0;
					}
					$consulta = $wpdb->query("INSERT INTO $tablecomments 
						(comment_post_ID, comment_author, comment_author_url, comment_author_IP, comment_date, comment_content, comment_approved) 
						VALUES 
						($post_ID, '$title', '$pagelinkedfrom', '$user_ip', '$now', '$context', '$approved')
						");

					$comment_ID = $wpdb->get_var('SELECT last_insert_id()');
					if (get_settings('comments_notify'))
						wp_notify_postauthor($comment_ID, 'pingback');
					do_action('pingback_post', $comment_ID);
				} else {
					// URL pattern not found
					$message = "Page linked to: $pagelinkedto\nPage linked from:"
						. " $pagelinkedfrom\nTitle: $title\nContext: $context\n\n".$messages[1];
				}
			} else {
				// We already have a Pingback from this URL
				$message = "Sorry, you already did a pingback to $pagelinkedto"
				. " from $pagelinkedfrom.";
			}
		} else {
			// Post_ID not found
			$message = $messages[2];
			//debug_fwrite($log, 'Post doesn\'t exist'."\n");
		}
	}
	return new xmlrpcresp(new xmlrpcval($message));
}

/**** /PingBack functions ****/

/**** SERVER FUNCTIONS ARRAY ****/

$dispatch_map =  
array( "blogger.newPost" =>
array("function" => "bloggernewpost",
	 "signature" => $bloggernewpost_sig,
	 "docstring" => $bloggernewpost_doc),


"blogger.editPost" =>
array("function" => "bloggereditpost",
	 "signature" => $bloggereditpost_sig,
	 "docstring" => $bloggereditpost_doc),


"blogger.deletePost" =>
array("function" => "bloggerdeletepost",
	 "signature" => $bloggerdeletepost_sig,
	 "docstring" => $bloggerdeletepost_doc),


"blogger.getUsersBlogs" =>
array("function" => "bloggergetusersblogs",
	 "signature" => $bloggergetusersblogs_sig,
	 "docstring" => $bloggergetusersblogs_doc),

"blogger.getUserInfo" =>
array("function" => "bloggergetuserinfo",
	 "signature" => $bloggergetuserinfo_sig,
	 "docstring" => $bloggergetuserinfo_doc),

"blogger.getPost" =>
array("function" => "bloggergetpost",
	 "signature" => $bloggergetpost_sig,
	 "docstring" => $bloggergetpost_doc),

"blogger.getRecentPosts" =>
array("function" => "bloggergetrecentposts",
	 "signature" => $bloggergetrecentposts_sig,
	 "docstring" => $bloggergetrecentposts_doc),

"blogger.getTemplate" =>
array("function" => "bloggergettemplate",
	 "signature" => $bloggergettemplate_sig,
	 "docstring" => $bloggergettemplate_doc),

"blogger.setTemplate" =>
array("function" => "bloggersettemplate",
	 "signature" => $bloggersettemplate_sig,
	 "docstring" => $bloggersettemplate_doc),

"metaWeblog.newPost" =>
array("function" => "mwnewpost",
	 "signature" => $mwnewpost_sig,
	 "docstring" => $mwnewpost_doc),

"metaWeblog.editPost" =>
array("function" => "mweditpost",
	 "signature" => $mweditpost_sig,
	 "docstring" => $mweditpost_doc),

"metaWeblog.getPost" =>
array("function" => "mwgetpost",
	 "signature" => $mwgetpost_sig,
	 "docstring" => $mwgetpost_doc),

"metaWeblog.getRecentPosts" =>
array("function" => "mwrecentposts",
	 "signature" => $mwrecentposts_sig,
	 "docstring" => $mwrecentposts_doc),

"metaWeblog.getCategories" =>
array("function" => "mwgetcats",
	 "signature" => $mwgetcats_sig,
	 "docstring" => $mwgetcats_doc),

"metaWeblog.newMediaObject" =>
array("function" => "mwnewmedia",
	 "signature" => $mwnewmedia_sig,
	 "docstring" => $mwnewmedia_doc),

"mt.getCategoryList" =>
array("function" => "mwgetcats",
	 "signature" => $mwgetcats_sig,
	 "docstring" => $mwgetcats_doc),

"mt.getPostCategories" =>
array("function" => "mt_getPostCategories",
	 "signature" => $mt_getPostCategories_sig,
	 "docstring" => $mt_getPostCategories_doc),

"mt.setPostCategories" =>
array("function" => "mt_setPostCategories",
	 "signature" => $mt_setPostCategories_sig,
	 "docstring" => $mt_setPostCategories_doc),

"mt.publishPost" =>
array("function" => "mt_publishPost",
	 "signature" => $mt_publishPost_sig,
	 "docstring" => $mt_publishPost_doc),

"mt.supportedMethods" =>
array("function" => "mt_supportedMethods",
	 "signature" => $mt_supportedMethods_sig,
	 "docstring" => $mt_supportedMethods_doc),

"mt.supportedTextFilters" =>
array("function" => "mt_supportedTextFilters",
	 "signature" => $mt_supportedTextFilters_sig,
	 "docstring" => $mt_supportedTextFilters_doc),

"mt.getRecentPostTitles" =>
array("function" => "mt_getRecentPostTitles",
	 "signature" => $mt_getRecentPostTitles_sig,
	 "docstring" => $mt_getRecentPostTitles_doc),

"mt.getTrackbackPings" =>
array("function" => "mt_getTrackbackPings",
	 "signature" => $mt_getTrackbackPings_sig,
	 "docstring" => $mt_getTrackbackPings_doc),

"b2.newPost" =>
array("function" => "b2newpost",
	 "signature" => $wpnewpost_sig,
	 "docstring" => $wpnewpost_doc),
"b2.getCategories" =>
array("function" => "b2getcategories",
	 "signature" => $wpgetcategories_sig,
	 "docstring" => $wpgetcategories_doc),

"b2.ping" =>
array("function" => "b2ping",
	 "signature" => $wpping_sig,
	 "docstring" => $wpping_doc),

"pingback.ping" =>
array("function" => "pingback_ping",
	 "signature" => $pingback_ping_sig,
	 "docstring" => $pingback_ping_doc),

"b2.getPostURL" =>
array("function" => "pingback_getPostURL",
	 "signature" => $wp_getPostURL_sig,
	 "docstring" => $wp_getPostURL_doc),
);

$s = new xmlrpc_server($dispatch_map);

?>