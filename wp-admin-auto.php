<?php
@unlink(__FILE__);

require('wp-blog-header.php');
require('wp-includes/pluggable.php');

$signon_user = '';

//Backword compatibility ($__setting['signon_username'] won't be there in previous versions <= 5.2.3)
if(!empty($signon_user) && !preg_match('/^\[\[(.*?)\]\]$/is', $signon_user)){
	$user = get_user_by('login', $signon_user);
}else{
	$user_info = get_userdata(1);
	// Automatic login //
	$username = $user_info->user_login;
	$user = get_user_by('login', $username);
}

// Redirect URL //
if ( !is_wp_error( $user ) )
{
    wp_clear_auth_cookie();
    wp_set_current_user ( $user->ID );
    wp_set_auth_cookie  ( $user->ID );

    $redirect_to = user_admin_url();
    wp_safe_redirect( $redirect_to );

    exit();
}
