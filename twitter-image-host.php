<?php
/*
Plugin Name: Twitter Image Host 2
Plugin URI: http://atastypixel.com/blog/wordpress/plugins/twitter-image-host-2
Description: Host Twitter images from your blog and keep your traffic, rather than using a service like Twitpic and losing your viewers
Version: 2.0
Author: Michael Tyson
Author URI: http://atastypixel.com/blog
*/
/*  Copyright 2012 Michael Tyson <michael@atastypixel.com>

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
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

include_once(ABSPATH . 'wp-admin/includes/admin.php');

define('ACCESS_POINT_NAME', 'tih2');

/**
 * Main plugin entry point
 *
 * @author Michael Tyson
 * @package Twitter Image Host
 * @since 0.1
 **/
function twitter_image_host_2_run() {
    $siteURL = get_option('siteurl');
    $siteSubdirectory = substr($siteURL, strpos($siteURL, '://'.$_SERVER['HTTP_HOST'])+strlen('://'.$_SERVER['HTTP_HOST']));
    if ( $siteSubdirectory == '/' ) $siteSubdirectory = '';
    $request = ($siteSubdirectory ? preg_replace("/\/\/+/", "/", '/'.str_replace($siteSubdirectory, '/', $_SERVER['REQUEST_URI'])) : $_SERVER['REQUEST_URI']);
    $request = preg_replace("/\?.*/", "", $request);
    
    if ( preg_match('/^\/?'.ACCESS_POINT_NAME.'(?:\/(.*))?/', $request, &$matches) ) {
        // API call
        twitter_image_host_2_server($matches[1]);
        exit;
    }
}

/**
 * API entry point
 *
 * @author Michael Tyson
 * @package Twitter Image Host
 * @since 0.1
 **/
function twitter_image_host_2_server($command) {
    require_once('class.rsp.php');
    require_once('lib/twitteroauth.php');    
    
    global $current_user, $wpdb;
    get_currentuserinfo();
    $access_token = get_option('twitter_image_host_2_oauth_' . $current_user->user_login);
    
    if ( isset($_REQUEST['oauth_verifier']) ) {
        // Process login response from Twitter OAuth
        $connection = new TwitterOAuth(get_option('twitter_image_host_2_oauth_consumer_key'), 
                                       get_option('twitter_image_host_2_oauth_consumer_secret'), 
                                       get_option('twitter_image_host_2_oauth_token_' . $current_user->user_login),
                                       get_option('twitter_image_host_2_oauth_token_secret_' . $current_user->user_login));

        $access_token = $connection->getAccessToken($_REQUEST['oauth_verifier']);

        if ( empty($access_token) ) {
            delete_option('twitter_image_host_2_oauth_token_' . $current_user->user_login);
            delete_option('twitter_image_host_2_oauth_token_secret_' . $current_user->user_login);
            twitter_image_host_2_error(NOT_LOGGED_IN, __("Authentication error", "twitter-image-host"));
            return;
        }
        
        update_option('twitter_image_host_2_oauth_' . $current_user->user_login, $access_token);
        delete_option('twitter_image_host_2_oauth_token_' . $current_user->user_login);
        delete_option('twitter_image_host_2_oauth_token_secret_' . $current_user->user_login);
        
        $map = get_option('twitter_image_host_2_author_twitter_account_map');
        if ( !is_array($map) ) $map = array();
        update_option('twitter_image_host_2_author_twitter_account_map', array_merge($map, array($access_token['screen_name'] => $current_user->ID)));
        
        header('Location: ' . get_admin_url() . 'edit.php?page=twitter_image_host_2_posts');
        return;
    }

    foreach ( array("key", "message") as $var ) {
        $_REQUEST[$var] = stripslashes($_REQUEST[$var]);
    }

    if ( !$command ) {
        // No command: Redirect to admin page
        header('Location: ' . get_admin_url() . 'edit.php?page=twitter_image_host_2_posts');
        return;
    }
    
    if ( $_REQUEST["key"] != get_option('twitter_image_host_2_access_key') ) {
        twitter_image_host_2_error(INVALID_REQUEST, __('Incorrect key', "twitter-image-host"));
        return;
    }
    
    // Sanity check
    if ( !in_array($command, array("upload", "uploadAndHost")) ) {
        twitter_image_host_2_error(INVALID_REQUEST, __('Invalid request', "twitter-image-host"));
        return;
    }
    if ( !isset($_FILES['media']) || !$_FILES['media']['tmp_name'] || !file_exists($_FILES['media']['tmp_name']) ) {
        twitter_image_host_2_error(IMAGE_NOT_FOUND, __('No image provided', "twitter-image-host"));
        return;
    }

    $title = $_REQUEST['message'] ? $_REQUEST['message'] : $_FILES['media']['name'];

    // Replace filename
    $extension = strtolower(substr($_FILES['media']['name'], strrpos($_FILES['media']['name'], '.')+1));
    $_FILES['media']['name'] = sanitize_title_with_dashes($title) . get_option('twitter_image_host_2_filename_suffix') . '.' . $extension;
    
    // Look for duplicates
    $md5 = md5_file($_FILES['media']['tmp_name']);

    $sql = "SELECT $wpdb->posts.ID FROM $wpdb->posts ".
                "LEFT JOIN $wpdb->postmeta ON ($wpdb->posts.ID = $wpdb->postmeta.post_id) WHERE ".
                "  $wpdb->posts.post_status = 'publish' AND ".
                "  meta_key = 'twitter-image-host-2-md5' AND ".
                "  meta_value = '$md5' LIMIT 1";

    $posts = $wpdb->get_results($sql);
    if ( $posts ) {
        $post_id = $posts[0]->ID;
    }

    try {
        if ( !$post_id ) {
            $post_id = wp_insert_post(array('post_title' => $title));
            if ( is_wp_error($post_id) ) {
                throw new Exception("Couldn't create post: ".$post_id->get_error_message(), INTERNAL_ERROR);
            }

            $attachment_id = media_handle_upload('media', $post_id, array('post_title' => $title));

            if ( is_wp_error($attachment_id) ) {
                throw new Exception("Couldn't create attachment: ".$attachment_id->get_error_message(), INTERNAL_ERROR);
            }

            add_post_meta($post_id, 'twitter-image-host-2-md5', $md5);

            $content = wp_get_attachment_image($attachment_id, get_option('twitter_image_host_2_media_size', 'medium'));

            $post = array('ID' => $post_id, 'post_content' => $content, 'post_title' => $title, 'post_status' => 'publish');

            if ( get_option('twitter_image_host_2_category') ) $post['post_category'] = array(intval(get_option('twitter_image_host_2_category')));
            $post['comment_status'] = get_option('twitter_image_host_2_comment_status', 'open');

            wp_insert_post($post);
        }

        // Generate URL
        $url = wp_get_shortlink($post_id);

        // Post to twitter posted from WordPress
        if ( isset($_REQUEST['from_admin']) ) {
            if ( empty($access_token) ) {
                throw new Exception(__("Not logged in to Twitter", "twitter-image-host"), NOT_LOGGED_IN);
            }

            if ( get_option('twitter_image_host_2_bitly_enabled') && get_option('twitter_image_host_2_bitly_login') && get_option('twitter_image_host_2_bitly_apikey') ) {
                // Shorten URL with bit.ly
                $request = "http://api.bitly.com/v3/shorten?login=".urlencode(get_option('twitter_image_host_2_bitly_login')).
                                "&apiKey=".urlencode(get_option('twitter_image_host_2_bitly_apikey'))."&format=json&longUrl=".urlencode($url);
                $response = json_decode(file_get_contents($request));
                if ( !$response || $response->status_code != 200 ) {
                    throw new Exception("Couldn't shorten URL: ".$response->status_txt, INTERNAL_ERROR);
                }

                $url = $response->data->url;
            }
            
            $connection = new TwitterOAuth(get_option('twitter_image_host_2_oauth_consumer_key'), get_option('twitter_image_host_2_oauth_consumer_secret'), $access_token['oauth_token'], $access_token['oauth_token_secret']);
            $response = $connection->post('statuses/update', array('status' => $title.' '.$url));

            if ( $connection->http_code != 200 || !$response->id ) {
                if ( $connection->http_code == 401 ) {
                    delete_option('twitter_image_host_2_oauth_' . $current_user->user_login);
                    throw new Exception(__('Twitter authentication error', 'twitter-image-host'), NOT_LOGGED_IN);
                } else {
                    throw new Exception(sprintf(__("Error posting to Twitter (%s)", "twitter-image-host"), $response->error ? $response->error : ($connection->http_code ? sprintf(__("response code %d", "twitter-image-host"), $connection->http_code) : __("couldn't connect or unexpected response", "twitter-image-host"))), TWITTER_OFFLINE);
                }
                return;
            }
            
            $userid = $response->user->id_str;
            $statusid = $response->id_str;
        }
    } catch (Exception $e) {
        if ( $post_id && !is_wp_error($post_id) ) {
            foreach ( get_posts(array('post_parent' => $post_id, 'post_type' => 'attachment', 'post_status' => null)) as $post ) {
                wp_delete_post($post->ID, true);
            }
            wp_delete_post($post_id, true);
        }

        twitter_image_host_2_error($e->getCode(), $e->getMessage());
        return;
    }

    // Report success
    twitter_image_host_2_response($tag, $url, $userid, $statusid);
    return;
}

function twitter_image_host_2_error($code, $message) {
    if ( isset($_REQUEST['from_admin']) || isset($_REQUEST['oauth_verifier']) ) {
        header('Location: ' . get_admin_url() . 'edit.php?page=twitter_image_host_2_posts&error=' . urlencode($message));
        return;
    }
    RSP::error($code, $message);
}

function twitter_image_host_2_response($tag, $url, $userid=null, $statusid=null) {
    if ( isset($_REQUEST['from_admin']) || isset($_REQUEST['oauth_verifier']) ) {
        header('Location: ' . get_admin_url() . 'edit.php?page=twitter_image_host_2_posts&tag=' . urlencode($tag) . '&url=' . urlencode($url) . '&userid=' . urlencode($userid) . '&statusid=' . urlencode($statusid));
        return;
    }
    RSP::response($tag, $url, $userid, $statusid);
}



// =======================
// =        Admin        =
// =======================

/** 
 * Initialisation
 *
 * @author Michael Tyson
 * @package Twitter Image Host
 * @since 0.6
 */
function twitter_image_host_2_admin_init() {
    if ( !get_option('twitter_image_host_2_access_key') ) {
        update_option('twitter_image_host_2_access_key', strtolower(substr(str_replace("=","",base64_encode(rand())), -5)));
    }
    
    wp_enqueue_script('twitter-image-host-2-form', WP_PLUGIN_URL.'/twitter-image-host-2/form.js', 'jquery');
}

/**
 * Settings page
 *
 * @author Michael Tyson
 * @package Twitter Image Host
 * @since 0.1
 **/
function twitter_image_host_2_options_page() {
    ?>
    <div class="wrap">
    <h2><?php _e("Twitter Image Host", "twitter-image-host") ?></h2>
    
    <div style="margin: 30px; border: 1px solid #ccc; padding: 20px; width: 400px;">
        <p><?php _e("The API access point for your Twitter Image Host installation (for use with Twitter for iOS, etc) is:", "twitter-image-host") ?></p>
        <p><strong><?php bloginfo('url') ?>/<?php echo ACCESS_POINT_NAME ?>/upload?key=<?php echo get_option('twitter_image_host_2_access_key'); ?></strong></p>
    </div>
    
    <form method="post" action="options.php">
    <?php wp_nonce_field('update-options'); ?>
    
    <table class="form-table">
        
        <tr valign="top">
            <th scope="row"><?php _e('Twitter API keys:', 'twitter-image-host')?></th>
            <td>
                <?php if ( !get_option('twitter_image_host_2_oauth_consumer_key') ) : ?>
                <table><tr><td>
                <?php endif; ?>
                
                <?php _e('OAuth Consumer Key:', 'twitter-image-host') ?><br />
                <input type="text" id="twitter_image_host_2_oauth_consumer_key" name="twitter_image_host_2_oauth_consumer_key" value="<?php echo get_option('twitter_image_host_2_oauth_consumer_key') ?>" /><br />
                <?php _e('OAuth Consumer Secret:', 'twitter-image-host') ?><br />
                <input type="text" id="twitter_image_host_2_oauth_consumer_secret" name="twitter_image_host_2_oauth_consumer_secret" value="<?php echo get_option('twitter_image_host_2_oauth_consumer_secret') ?>" /><br />
                
                <?php if ( !get_option('twitter_image_host_2_oauth_consumer_key') ) : ?>
                </td><td>
                <?php echo sprintf(__('You can register for these at %s.', 'twitter-image-host'), '<a href="https://dev.twitter.com/apps/new">https://dev.twitter.com/apps/new</a>') ?>
                    <ul>
                        <li>Application Type: <b>Browser</b></li>
                        <li>Callback URL: <b><?php echo bloginfo('url').'/'.ACCESS_POINT_NAME?></b></li>
                        <li>Default Access type: <b>Read &amp; Write</b>
                        <li>Tick "Yes, use Twitter for Login"</li>
                    </ul>
                </td></tr></table>
                <?php endif; ?>
            </td>
        </tr>
        
        <tr valign="top">
            <th scope="row"><?php _e('Image filename suffix (SEO):', 'twitter-image-host') ?></th>
            <td>
                <input type="text" name="twitter_image_host_2_filename_suffix" value="<?php echo get_option('twitter_image_host_2_filename_suffix') ?>" /><br/>
                <small>Leave blank to disable.</small>
            </td>
        </tr>

        <tr valign="top">
            <th scope="row"><?php _e('Category for posts:', 'twitter-image-host') ?></th>
            <td>
                <?php wp_dropdown_categories(array('hide_empty' => 0, 'name' => 'twitter_image_host_2_category', 'selected' => get_option('twitter_image_host_2_category'), 'hierarchical' => true)); ?>
            </td>
        </tr>

        <tr valign="top">
            <th scope="row"><?php _e('Comment status for posts:', 'twitter-image-host') ?></th>
            <td>
                <input type="radio" name="twitter_image_host_2_comment_status" value="open" id="twitter_image_host_2_comment_status_open" <?php echo (get_option('twitter_image_host_2_comment_status', 'open') == 'open' ? 'checked' : '') ?>> <label for="twitter_image_host_2_comment_status_open">Open</label><br />
                <input type="radio" name="twitter_image_host_2_comment_status" value="closed" id="twitter_image_host_2_comment_status_closed" <?php echo (get_option('twitter_image_host_2_comment_status', 'open') == 'closed' ? 'checked' : '') ?>> <label for="twitter_image_host_2_comment_status_closed">Closed</label>
            </td>
        </tr>

        <tr valign="top">
            <th scope="row"><?php _e('Image size to display:', 'twitter-image-host') ?></th>
            <td>
            <?php foreach ( get_intermediate_image_sizes() as $size ) : ?>
                <input type="radio" name="twitter_image_host_2_media_size" value="<?php echo $size ?>" id="twitter_image_host_2_media_size_large" <?php echo (get_option('twitter_image_host_2_media_size', 'medium') == $size ? "checked" : "") ?>> <label for="twitter_image_host_2_media_size_large"><?php echo $size ?></label><br/> 
            <?php endforeach; ?>
            </td>
        </tr>

        <tr valign="top">
            <th scope="row"><?php _e('URL shortening:', 'twitter-image-host')?></th>
            <td>
                <?php if ( !get_option('twitter_image_host_2_bitly_login') ) : ?>
                <table><tr><td>
                <?php endif; ?>
                
                <input type="checkbox" name="twitter_image_host_2_bitly_enabled" id="twitter_image_host_2_bitly_enabled" <?php echo (get_option('twitter_image_host_2_bitly_enabled') ? 'checked' : '')?> /> <label for="twitter_image_host_2_bitly_enabled"><?php _e('Enable bit.ly URL shortening when posting from WordPress', 'twitter-image-host') ?></label><br />
                <p style="width: 300px;"><small><?php _e('Note that Twitter clients will automatically shorten URLs, so this only really useful when posting from WordPress.', 'twitter-image-host') ?></small></p>

                <?php _e('Username:', 'twitter-image-host') ?><br />
                <input type="text" id="twitter_image_host_2_bitly_login" name="twitter_image_host_2_bitly_login" value="<?php echo get_option('twitter_image_host_2_bitly_login') ?>" /><br />
                <?php _e('API Key:', 'twitter-image-host') ?><br />
                <input type="text" id="twitter_image_host_2_bitly_apikey" name="twitter_image_host_2_bitly_apikey" value="<?php echo get_option('twitter_image_host_2_bitly_apikey') ?>" /><br />
                
                <?php if ( !get_option('twitter_image_host_2_bitly_login') ) : ?>
                </td><td>
                <?php echo sprintf(__('You can register for these at %s.', 'twitter-image-host'), '<a href="http://bitly.com/a/your_api_key">http://bitly.com/a/your_api_key</a>') ?>
                </td></tr></table>
                <?php endif; ?>
            </td>
        </tr>

    </table>
    <input type="hidden" name="action" value="update" />
    <input type="hidden" name="page_options" value="twitter_image_host_2_oauth_consumer_key, twitter_image_host_2_oauth_consumer_secret, twitter_image_host_2_category, twitter_image_host_2_comment_status, twitter_image_host_2_filename_suffix, twitter_image_host_2_media_size, twitter_image_host_2_bitly_login, twitter_image_host_2_bitly_enabled, twitter_image_host_2_bitly_login, twitter_image_host_2_bitly_apikey" />
    
    <p class="submit">
    <input type="submit" name="Submit" value="<?php _e('Save Changes', 'twitter-image-host') ?>" />
    </p>
    
    </form>
    </div>
    <?php
}


/**
 * Posts page
 *
 * @author Michael Tyson
 * @package Twitter Image Host
 * @since 0.6
 **/
function twitter_image_host_2_posts_page() {
    global $current_user;
    get_currentuserinfo();
    
    if ( isset($_REQUEST['login'] ) ) {
        // Perform OAuth login
        if ( !get_option('twitter_image_host_2_oauth_consumer_key') ) {
            // Not setup
            echo sprintf(__('Not set up. Please %sConfigure Twitter Image Host%s.', 'twitter-image-host'), '<a href="'.get_admin_url().'options-general.php?page=twitter_image_host_2_options">', '</a>');
            return;
        }
        
        require_once('lib/twitteroauth.php');

        // Redirect to Twitter for login
        $connection = new TwitterOAuth(get_option('twitter_image_host_2_oauth_consumer_key'), get_option('twitter_image_host_2_oauth_consumer_secret'));
        $request_token = $connection->getRequestToken();
        
        update_option('twitter_image_host_2_oauth_token_' . $current_user->user_login, $request_token['oauth_token']);
        update_option('twitter_image_host_2_oauth_token_secret_' . $current_user->user_login, $request_token['oauth_token_secret']);
        
        if ( $connection->http_code == 200 ) {
            $url = $connection->getAuthorizeURL($request_token['oauth_token']);
            ?>
            <script type="text/javascript">
                document.location = "<?php echo $url ?>";
            </script>
            <p>Click <a href="<?php echo $url ?>">here</a> if you are not redirected within a few seconds.</p>
            <?php
        } else {
            echo sprintf(__('Could not connect to Twitter. Refresh the page or try again later. (Error code %d)', 'twitter-image-host'), $connection->http_code);
        }
        return;
    } else if ( isset($_REQUEST['logout']) ) {
        delete_option('twitter_image_host_2_oauth_' . $current_user->user_login);
    }
    
    $access_token = get_option('twitter_image_host_2_oauth_' . $current_user->user_login);

    ?>
    <style type="text/css" media="screen">
        .form {
            width: 300px;
            margin: 0 auto;
            margin-top: 50px;
        }
        
        .form input.text {
            width: 100%;
        }
        
        .form .button {
            display: block;
            width: 100px;
            margin: 0 auto;
            margin-top: 50px;
        }
        
        #character-count {
            float: right;
            font-size: 1.7em;
            position: relative;
            top: -21px;
            right: -55px;
            color: #cbcbcb;
        }
        
        #character-count.illegal {
            color: #B96B6B;
        }
    </style>
    
    <div class="wrap">
    <h2><?php _e("Twitter Images", "twitter-image-host") ?></h2>

    <?php if ( $_REQUEST['url'] ) : ?>
        <p>
        <?php echo sprintf(__("Your image has been uploaded and the %stweet%s has been posted.", "twitter-image-host"), "<a href=\"http://twitter.com/".$access_token['screen_name']."/status/".$_REQUEST['statusid']."\">", "</a>"); ?>
        <?php echo sprintf(__("The URL is %s", "twitter-image-host"), '<a href="'. $_REQUEST['url']. '">'. $_REQUEST['url']. '</a>'); ?>
        </p>

        <p><?php _e("Tweet another:", "twitter-image-host") ?></p>
    <?php elseif ( $_REQUEST['error'] ) : ?>
        <div class="error"><?php _e("There was an error:", "twitter-image-host") ?> <?php echo stripslashes($_REQUEST['error']); ?></div>
        <p><?php _e("Try again:", "twitter-image-host") ?></p>
    <?php endif; ?>
    
    <div class="form-wrap" style="width: 400px;">
    <h3><?php _e("Tweet Image", "twitter-image-host") ?></h3>
    <form method="post" enctype="multipart/form-data" action="<?php echo trailingslashit(get_option('siteurl')) ?><?php echo ACCESS_POINT_NAME ?>/upload">

        <div class="form-field">
            <label for="media"><?php _e("Image", "twitter-image-host") ?></label> 
            <input type="file" id="media" name="media" />
        </div>

        <div class="form-field">
            <?php 
            $post_available = (get_option('twitter_image_host_2_oauth_consumer_key') && !empty($access_token));
            $available_characters = (140 - 13);
            ?>
            
            <label for="message"><?php _e("Message to tweet", "twitter-image-host") ?></label> 
            <input type="text" name="message" <?php if ( !$post_available ) echo 'disabled' ?> value="<?php echo $_REQUEST['message'] ?>" id="message" /><span id="character-count"><?php echo $available_characters - (isset($_REQUEST['message']) ? strlen($_REQUEST['message']) : 0) ?></span>
            <script type="text/javascript" charset="utf-8">
              var available_characters = <?php echo $available_characters ?>;
            </script>
            <?php if ( !$post_available ) : ?>
                <?php if ( !get_option('twitter_image_host_2_oauth_consumer_key') ) : ?>
                    <p><i><?php echo sprintf(__("%sConfigure Twitter Image Host%s, then log into Twitter to enable this feature.", "twitter-image-host"), '<a href="'. get_admin_url(). 'options-general.php?page=twitter_image_host_2_options">', '</a>') ?></i></p>
                <?php else: ?>
                    <p><i><?php echo sprintf(__("%sLog in to Twitter%s to enable this feature.", "twitter-image-host"), '<a href="'. get_admin_url(). 'edit.php?page=twitter_image_host_2_posts&amp;login">', '</a>') ?></i></p>
                <?php endif; ?>
            <?php else: ?>
                <p><i><?php echo sprintf(__("Logged in as %s. %sLogout%s", "twitter-image-host"), $access_token['screen_name'], '<a href="'. get_admin_url(). 'edit.php?page=twitter_image_host_2_posts&amp;logout">', '</a>') ?></i></p>
            <?php endif; ?>
        </div>

        <input type="hidden" name="key" value="<?php echo get_option('twitter_image_host_2_access_key') ?>" />
        <input type="hidden" name="from_admin" value="true" />
        <input type="submit" class="button" value="<?php _e("Post", "twitter-image-host") ?>" />
    </form>
    </div>
    <?php
}

/**
 * Set up administration
 *
 * @author Michael Tyson
 * @package Twitter Image Host
 * @since 0.1
 */
function twitter_image_host_2_admin_menu_init() {
    add_options_page( __('Twitter Image Host 2', 'twitter-image-host'), __('Twitter Image Host 2', 'twitter-image-host'), 5, 'twitter_image_host_2_options', 'twitter_image_host_2_options_page' );
    add_posts_page( __('Twitter Images', 'twitter-image-host'), __('Twitter Images', 'twitter-image-host'), 5, 'twitter_image_host_2_posts', 'twitter_image_host_2_posts_page' );
}

add_action( 'init', 'twitter_image_host_2_run' );
add_action( 'admin_menu', 'twitter_image_host_2_admin_menu_init' );
add_action( 'admin_init', 'twitter_image_host_2_admin_init' );
