<?php
/*
 * Copyright � 2008-2010 Garrett Brown <http://www.mediawiki.org/wiki/User:Gbruin>
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License along
 * with this program. If not, see <http://www.gnu.org/licenses/>.
 */


/*
 * Not a valid entry point, skip unless MEDIAWIKI is defined.
 */
if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is a MediaWiki extension, it is not a valid entry point' );
}


/**
 * Class FacebookAPI
 * 
 * This class contains the code used to interface with Facebook via the
 * Facebook Platform API.
 */
class FacebookAPI extends Facebook {
	// Constructor
	public function __construct() {
		global $wgFbAppId, $wgFbSecret, $wgFbDomain;
		// Check to make sure config.default.php was renamed properly, unless we
		// are running update.php from the command line
		// TODO: use $wgCommandLineMode, if it is propper to do so
		if ( !defined( 'MW_CMDLINE_CALLBACK' ) && !$this->isConfigSetup() ) {
			die ( '<strong>Please update $wgFbAppId and $wgFbSecret.</strong>' );
		}
		$config = array(
			'appId'      => $wgFbAppId,
			'secret'     => $wgFbSecret,
			'fileUpload' => false, // optional
		);
		/*
		// Include the optional domain parameter if it has been set
		if ( !empty( $wgFbDomain ) && $wgFbDomain != 'BASE_DOMAIN' ) {
			$config['domain'] = $wgFbDomain;
		}
		*/
		parent::__construct( $config );
	}
	
	/**
	 * Check to make sure config.sample.php was properly renamed to config.php
	 * and the instructions to fill out the first two important variables were
	 * followed correctly.
	 */
	public function isConfigSetup() {
		global $wgFbAppId, $wgFbSecret;
		$isSetup = isset( $wgFbAppId ) && $wgFbAppId != 'YOUR_APP_KEY' &&
		           isset( $wgFbSecret ) && $wgFbSecret != 'YOUR_SECRET';
		if( !$isSetup ) {
			// Check to see if they are still using the old variables
			global $fbApiKey, $fbApiSecret;
			if ( isset( $fbApiKey ) ) {
				$wgFbAppId = $fbApiKey;
			}
			if ( isset( $fbApiSecret ) ) {
				$wgFbSecret= $fbApiSecret;
			}
			$isSetup = isset( $wgFbAppId ) && $wgFbAppId != 'YOUR_APP_KEY' &&
		               isset( $wgFbSecret ) && $wgFbSecret != 'YOUR_SECRET';
		}
		return $isSetup;
	}
	
	/**
	 * Requests information about the user from Facebook.
	 * 
	 * Possible fields are id, name, first_name, last_name, username, gender, locale, email
	 */
	public function getUserInfo( $userId = 0 ) {
		// First check to see if we have a session (if not, return null)
		if ( $userId == 0 ) {
			$userId = $this->getUser();
		}
		if ( !$userId ) {
			return null;
		}
		
		// Cache information about users
		static $userinfo = array();
		if ( !isset( $userinfo[$userId] ) ) {
			try {
				// Can't use /me here. If our token is acquired for a Facebook Application,
				// then "me" isn't you anymore - it's the app or maybe nothing.
				// http://stackoverflow.com/questions/2705756/facebook-access-token-invalid
				$userinfo[$userId] = $this->api('/' . $userId);
			} catch (FacebookApiException $e) {
				error_log( 'Failure in the api when requesting /me: ' . $e->getMessage() );
			}
		}
		
		return isset($userinfo[$userId]) ? $userinfo[$userId] : null;
	}
	
	/**
	 * Retrieves group membership data from Facebook.
	 */
	public function getGroupRights( $user = null ) {
		global $wgFbUserRightsFromGroup;
		
		// Groupies can be members, officers or admins (the latter two infer the former)
		$rights = array(
			'member'  => false,
			'officer' => false,
			'admin'   => false
		);
		
		$gid = !empty( $wgFbUserRightsFromGroup ) ? $wgFbUserRightsFromGroup : false;
		// If no group ID is specified, then there's no group to belong to
		if ( !$gid ) {
			return $rights;
		}
		// If $user wasn't specified, set it to the logged in user
		if ( $user === null ) {
			$user = $this->getUser();
			// If there is no logged in user
			if ( !$user ) {
				return $rights;
			}
		} else if ( $user instanceof User ) {
			// If a User object was provided, translate it into a Facebook ID
			// TODO: Does this call for a special api call without access_token?
			$users = FacebookDB::getFacebookIDs( $user );
			if ( count($users) ) {
				$user = $users[0];
			} else {
				// Not a Connected user, can't be in a group
				return $rights;
			}
		}
		
		// Cache the rights for an individual user to prevent hitting Facebook for duplicate info
		static $rights_cache = array();
		if ( array_key_exists( $user, $rights_cache ) ) {
			// Retrieve the rights from the cache
			return $rights_cache[$user];
		}
		
		/*
		 * We query for group members using the old REST API instead of the new
		 * Graph API. The REST API is the only one that will allow querying of
		 * Group Officers, because the concept of officers has been removed in
		 * the reintroduction of Facebook Groups.
		 * 
		 * Announcement: <http://www.facebook.com/blog.php?post=434700832130>
		 * Officer disappearance: <http://www.facebook.com/help/?faq=14511>
		 */
		// This can contain up to 500 IDs, avoid requesting this info twice
		static $members = false;
		// Get a random 500 group members, along with officers, admins and not_replied's
		if ( $members === false ) {
			// Check to make sure our session is still valid
			try {
				$members = $this->api( array(
					'method' => 'groups.getMembers',
					'gid' => $gid
				));
			} catch ( FacebookApiException $e ) {
				// Invalid session; we're not going to be able to get the rights
				error_log( $e );
				$rights_cache[$user] = $rights;
				return $rights;
			}
		}
		
		if ( $members ) {
			// Check to see if the user is an officer
			if ( array_key_exists( 'officers', $members ) && in_array( $user, $members['officers'] ) ) {
				$rights['member'] = $rights['officer'] = true;
			}
			// Check to see if the user is an admin of the group
			if ( array_key_exists( 'admins', $members ) && in_array( $user, $members['admins'] ) ) {
				$rights['member'] = $rights['admin'] = true;
			}
			// Because the latter two rights infer the former, this step isn't always necessary
			if( !$rights['member'] ) {
				// Check to see if we are one of the (up to 500) random users
				if ( ( array_key_exists( 'not_replied', $members ) && is_array( $members['not_replied'] ) &&
					in_array( $user, $members['not_replied'] ) ) || in_array( $user, $members['members'] ) ) {
					$rights['member'] = true;
				} else {
					// For groups of over 500ish, we must use this extra API call
					// Notice that it occurs last, because we can hopefully avoid having to call it
					try {
						$group = $this->api( array(
							'method' => 'groups.get',
							'uid' => $user,
							'gids' => $gid
						));
					} catch ( FacebookApiException $e ) {
						error_log( $e );
					}
					if ( is_array( $group ) && is_array( $group[0] ) && $group[0]['gid'] == "$gid" ) {
						$rights['member'] = true;
					}
				}
			}
		}
		// Cache the rights
		$rights_cache[$user] = $rights;
		return $rights;
	}
	
	/*
	 * Publish message on Facebook wall.
	 */
	public function publishStream( $href, $description, $short, $link, $img ) {
		/*
		// Retrieve the message and substitute the params for the actual values
		$msg = wfMsg( $message_name ) ;
		foreach ($params as $key => $value) {
		 	$msg = str_replace($key, $value, $msg);
		}
		// If $FB_NAME isn't provided, simply blank it out
		$msg = str_replace('$FB_NAME', '', $msg);
		
		/**/
		$attachment = array(
			'name' => $link,
			'href' => $href,
			'description' => $description,
			'media' => array(array(
				'type' => 'image',
				'src' => $img,
				'href' => $href,
			)),
		);
		/*
		if( count( $media ) > 0 ) {
			foreach ( $media as $value ) {
				$attachment['media'][] = $value;
			}
		}
		/**/
		
		$query = array(
			'method' => 'stream.publish',
			'message' => $short,
			'attachment' => json_encode( $attachment ),
			/*
			'action_links' => json_encode( array(
				'text' => $link_title,
				'href' => $link
			)),
			/**/
		);
		
		// Submit the query and decode the result
		$result = json_decode( $this->api( $query ) );
		
		if ( is_array( $result ) ) {
			// Error
			#error_log( FacebookAPIErrorCodes::$api_error_descriptions[$result] );
			error_log( "stream.publish returned error code $result->error_code" );
			return $result->error_code;
		}
		else if ( is_string( $result ) ) {
			// Success! Return value is "$UserId_$PostId"
			return 0;
		} else {
			error_log( 'stream.publish: Unknown return type: ' . gettype( $result ) );
			return -1;
		}
	}
	
	/**
	 * Verify that the user ID matches the hash provided by the GET parameters
	 * in the account reclaimation link. This algorithm comes from the function
	 * Facebook::verify_account_reclamation($user, $hash) in the old Facebook
	 * PHP Client Library (replaced by the PHP SDK in 2010).
	 * 
	 * See also <http://wiki.developers.facebook.com/index.php/Reclaiming_Accounts>.
	 */
	function verifyAccountReclamation( $fb_user_id, $hash ) {
		if ( $hash != md5( $user . $this->apiSecret ) ) {
			return false;
		}
		return FacebookDB::getUser( $fb_user_id );
	}
}
