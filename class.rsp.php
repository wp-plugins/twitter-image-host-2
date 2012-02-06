<?php

// Based on img.ly's API: http://img.ly/pages/API

/**
 * Errors
 */
define('INVALID_REQUEST', 1); 
define('INCORRECT_ACCESS_KEY', 2);
define('INTERNAL_ERROR', 3);
define('TWITTER_OFFLINE', 4);
define('TWEET_TOO_LONG', 5);
define('TWITTER_POST_ERROR', 6);

define('NOT_LOGGED_IN', 1001);
define('IMAGE_NOT_FOUND', 1002);
define('INVALID_IMAGE', 1003);
define('IMAGE_TOO_LARGE', 1004);

/**
 * RSP response generator
 */
class RSP {
    
    function response($mediaID, $mediaURL, $userID=null, $statusID=null) {
        header('Content-type: text/xml');
        echo RSP::generate_response($mediaID, $mediaURL, $userID, $statusID);
    }
    
    function error($code, $message) {
        header('Content-type: text/xml');
        echo RSP::generate_error($code, $message);
    }
    
    function generate_response($mediaID, $mediaURL, $userID=null, $statusID=null) {
        return RSP::container('ok', RSP::generate(array(
            'statusid' => $statusID,
            'userid' => $userID,
            'mediaid' => $mediaID,
            'mediaurl' => $mediaURL
            )));
    }
    
    function generate_error($code, $message) {
        return RSP::container('fail', '<err code="'.$code.'" msg="'.htmlspecialchars($message).'" />'."\n");
    }
    
    function container($stat, $content) {
        return 
            '<?xml version="1.0" encoding="UTF-8"?>'."\n".
            '<rsp stat="'.$stat.'">'."\n".
                "\t".trim(str_replace("\n", "\n\t", $content))."\n".
            '</rsp>'."\n";
    }
    
    function generate($objs) {
        if ( !is_array($objs) ) $objs = array($objs);
        $resp = '';
        foreach ( $objs as $id => $val ) {
            if ( !$val ) continue;
            $resp .= "<$id>".htmlspecialchars($val)."</$id>\n";
        }
        return $resp;
    }
}


?>