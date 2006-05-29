<?php
/**
 * This file implements the Session class.
 *
 * A session can be bound to a user and provides functions to store data in its
 * context.
 * All Hitlogs are also bound to a Session.
 *
 * This file is part of the evoCore framework - {@link http://evocore.net/}
 * See also {@link http://sourceforge.net/projects/evocms/}.
 *
 * @copyright (c)2003-2006 by Francois PLANQUE - {@link http://fplanque.net/}
 * Parts of this file are copyright (c)2004-2006 by Daniel HAHLER - {@link http://thequod.de/contact}.
 *
 * {@internal License choice
 * - If you have received this file as part of a package, please find the license.txt file in
 *   the same folder or the closest folder above for complete license terms.
 * - If you have received this file individually (e-g: from http://cvs.sourceforge.net/viewcvs.py/evocms/)
 *   then you must choose one of the following licenses before using the file:
 *   - GNU General Public License 2 (GPL) - http://www.opensource.org/licenses/gpl-license.php
 *   - Mozilla Public License 1.1 (MPL) - http://www.opensource.org/licenses/mozilla1.1.php
 * }}
 *
 * {@internal Open Source relicensing agreement:
 * Daniel HAHLER grants Francois PLANQUE the right to license
 * Daniel HAHLER's contributions to this file and the b2evolution project
 * under any OSI approved OSS license (http://www.opensource.org/licenses/).
 *
 * Matt FOLLETT grants Francois PLANQUE the right to license
 * Matt FOLLETT's contributions to this file and the b2evolution project
 * under any OSI approved OSS license (http://www.opensource.org/licenses/).
 * }}
 *
 * @package evocore
 *
 * {@internal Below is a list of authors who have contributed to design/coding of this file: }}
 * @author blueyed: Daniel HAHLER.
 * @author fplanque: Francois PLANQUE.
 * @author jeffbearer: Jeff BEARER - {@link http://www.jeffbearer.com/}.
 * @author mfollett:  Matt FOLLETT - {@link http://www.mfollett.com/}.
 *
 * @version $Id$
 */
if( !defined('EVO_MAIN_INIT') ) die( 'Please, do not access this page directly.' );


/**
 * A session tracks a given user (not necessarily logged in) while he's navigating the site.
 * A sessions also stores data for the length of the session.
 *
 * Sessions are tracked with a cookie containing the session ID.
 * The cookie also contains a random key to prevent sessions hacking.
 *
 * @package evocore
 */
class Session
{
	/**
	 * The ID of the session.
	 * @var integer
	 */
	var $ID;

	/**
	 * The session key (to be used in URLs).
	 * @var string
	 */
	var $key;

	/**
	 * The user ID for the user of the session (NULL for anonymous (not logged in) user).
	 * @var integer|NULL
	 */
	var $user_ID;

	/**
	 * Is the session validated?
	 * This means that it was created from a received cookie.
	 * @var boolean
	 */
	var $is_validated = false;

	/**
	 * Data stored for the session.
	 *
	 * This holds an array( expire, value ) for each data item key.
	 *
	 * @access protected
	 * @var array
	 */
	var $_data;

	var $_session_needs_save = false;


	/**
	 * Constructor
	 */
	function Session()
	{
		global $DB, $Debuglog, $current_User, $localtimenow, $Messages, $Settings;
		global $Hit;
		global $cookie_session, $cookie_expires, $cookie_path, $cookie_domain;

		if( !empty( $_COOKIE[$cookie_session] ) )
		{ // session ID sent by cookie
			if( ! preg_match( '~^(\d+)_(\w+)$~', remove_magic_quotes($_COOKIE[$cookie_session]), $match ) )
			{
				$Debuglog->add( 'Invalid session cookie format!', 'session' );
			}
			else
			{	// We have a valid session cookie:
				$session_id_by_cookie = $match[1];
				$session_key_by_cookie = $match[2];

				$Debuglog->add( 'ID (from cookie): '.$session_id_by_cookie, 'session' );

				$row = $DB->get_row( '
					SELECT sess_ID, sess_key, sess_data, sess_user_ID
					  FROM T_sessions
					 WHERE sess_ID  = '.$DB->quote($session_id_by_cookie).'
					   AND sess_key = '.$DB->quote($session_key_by_cookie).'
					   AND sess_lastseen > '.($localtimenow - $DB->quote($Settings->get('timeout_sessions'))) );
				if( empty( $row ) )
				{
					$Debuglog->add( 'Session ID/key combination is invalid!', 'session' );
				}
				else
				{ // ID + key are valid: load data
					$Debuglog->add( 'ID is valid.', 'session' );
					$this->ID = $row->sess_ID;
					$this->key = $row->sess_key;
					$this->user_ID = $row->sess_user_ID;
					$this->is_validated = true;

					$Debuglog->add( 'user_ID: '.var_export($this->user_ID, true), 'session' );

					if( empty( $row->sess_data ) )
					{
						$Debuglog->add( 'No session data available.', 'session' );
						$this->_data = array();
					}
					else
					{ // Some session data has been previsouly stored:

						// Unserialize session data (using an own callback that should provide class definitions):
						// fp> TODO: that function should probably be over here; plus we'll have a php 4 class loader anyway 
						$old_callback = ini_get( 'unserialize_callback_func' );
						ini_set( 'unserialize_callback_func', 'unserialize_callback' );
						$this->_data = @unserialize($row->sess_data);
						ini_set( 'unserialize_callback_func', $old_callback );

						if( $this->_data === false )
						{
							$Debuglog->add( 'Session data corrupted! Unserialized data was: ['.var_export($row->sess_data, true).']', array('session','error') );
							$this->_data = array();
						}
						else
						{
							$Debuglog->add( 'Session data loaded.', 'session' );

							// Load a Messages object from session data, if available:
							if( ($sess_Messages = $this->get('Messages')) && is_a( $sess_Messages, 'log' ) )
							{
								$Messages->add_messages( $sess_Messages->messages );
								$this->delete( 'Messages' );
								$Debuglog->add( 'Added Messages from session data.', 'session' );
							}
						}
					}
				}
			}
		}


		if( $this->ID )
		{ // there was a valid session before; update data
			$this->_session_needs_save = true;
		}
		else
		{ // create a new session
			$this->key = generate_random_key(32);

			// We need to INSERT now because we need an ID now! (for the cookie)
			$DB->query( '
				INSERT INTO T_sessions( sess_key, sess_lastseen, sess_ipaddress )
				VALUES (
					"'.$this->key.'",
					"'.date( 'Y-m-d H:i:s', $localtimenow ).'",
					"'.$Hit->IP.'"
				)' );

			$this->ID = $DB->insert_id;

			// Set a cookie valid for ~ 10 years:
			setcookie( $cookie_session, $this->ID.'_'.$this->key, time()+315360000, $cookie_path, $cookie_domain );

			$Debuglog->add( 'ID (generated): '.$this->ID, 'session' );
			$Debuglog->add( 'Cookie sent.', 'session' );
		}

		register_shutdown_function( array( & $this, 'dbsave' ) );
	}


	/**
	 * Attach a User object to the session.
	 *
	 * @param User The user to attach
	 */
	function set_User( $User )
	{
		return $this->set_user_ID( $User->get('ID') );
	}


	/**
	 * Attach a user ID to the session.
	 *
	 * NOTE: ID gets saved to DB on shutdown. This may be a "problem" when querying T_sessions for sess_user_ID.
	 *
	 * @param integer The ID of the user to attach
	 */
	function set_user_ID( $user_ID )
	{
		if( $user_ID != $this->user_ID )
		{
			$this->user_ID = $user_ID;
			$this->_session_needs_save = true;
		}
	}


	/**
	 * Logout the user, by invalidating the session key and unsetting {@link $user_ID}.
	 *
	 * We want to keep the user in the session log, but we're unsetting {@link $user_ID}, which refers
	 * to the current session.
	 *
	 * Because the session key is invalid/broken, on the next request a new session will be started.
	 *
	 * NOTE: we MIGHT want to link subsequent sessions together if we want to keep track...
	 */
	function logout()
	{
		global $Debuglog, $cookie_session, $cookie_path, $cookie_domain;

		// Invalidate the session key (no one will be able to use this session again)
		$this->key = NULL;
		$this->_data = array(); // We don't need to keep old data
		$this->_session_needs_save = true;
		$this->dbsave();

		$this->user_ID = NULL; // Unset user_ID after invalidating/saving the session above, to keep the user info attached to the old session.

		// clean up the session cookie:
		setcookie( $cookie_session, '', 200000000, $cookie_path, $cookie_domain );
	}


	/**
	 * Check if session has a user attached.
	 *
	 * @return boolean
	 */
	function has_User()
	{
		return !empty( $this->user_ID );
	}


	/**
	 * Get the attached User.
	 *
	 * @return false|User
	 */
	function & get_User()
	{
		global $UserCache;

		if( !empty($this->user_ID) )
		{
			return $UserCache->get_by_ID( $this->user_ID );
		}

		$r = false;
		return $r;
	}


	/**
	 * Get a data value for the session. This checks for the data to be expired and unsets it then.
	 *
	 * @param string Name of the data's key.
	 * @return mixed|NULL The value, if set; otherwise NULL
	 */
	function get( $param )
	{
		global $Debuglog, $localtimenow;

		if( isset( $this->_data[$param] ) )
		{
			if( isset($this->_data[$param][1])
			  && ( ! isset( $this->_data[$param][0] ) || $this->_data[$param][0] > $localtimenow ) ) // check for expired data
			{
				return $this->_data[$param][1];
			}
			else
			{ // expired or old format (without 'value' key)
				unset( $this->_data[$param] );
				$this->_session_needs_save = true;
				$Debuglog->add( 'Session data['.$param.'] expired.', 'session' );
			}
		}

		return NULL;
	}


	/**
	 * Set a data value for the session.
	 *
	 * @param string Name of the data's key.
	 * @param mixed The value
	 * @param integer Time in seconds for data to expire (0 to disable).
	 */
	function set( $param, $value, $expire = 0 )
	{
		global $Debuglog, $localtimenow;

		if( ! isset($this->_data[$param])
		 || ! is_array($this->_data[$param]) // deprecated: check to transform 1.6 session data to 1.7
		 || $this->_data[$param][1] != $value
		 || $expire != 0 )
		{	// There is something to update:
			$this->_data[$param] = array( ( $expire ? ($localtimenow + $expire) : NULL ), $value );

			// fp> TODO: This is dirty! The session class should not CARE about preview comments. This should be set by the Preview caller!
			if( in_array( $param, array( 'Messages', 'core.preview_Comment' ) ) )
			{ // also set boolean to not call CachePageContent plugin event on next request:
				$this->set( 'core.no_CachePageContent', 1 );
			}

			$Debuglog->add( 'Session data['.$param.'] updated. Expire in: '.( $expire ? $expire.'s' : '-' ).'.', 'session' );

			$this->_session_needs_save = true;
		}
	}


	/**
	 * Delete a value from the session data.
	 *
	 * @param string Name of the data's key.
	 */
	function delete( $param )
	{
		global $Debuglog;

		if( isset($this->_data[$param]) )
		{
			unset( $this->_data[$param] );

			$Debuglog->add( 'Session data['.$param.'] deleted!', 'session' );

			$this->_session_needs_save = true;
		}
	}


	/**
	 * Updates session data in database.
	 *
	 * Note: The key actually only needs to be updated on a logout.
	 */
	function dbsave()
	{
		global $DB, $Debuglog, $Hit, $localtimenow;

		if( ! $this->_session_needs_save )
		{	// There have been no changes since the last save.
			return false;
		}

		$sess_data = empty($this->_data) ? NULL : serialize($this->_data);
		$DB->query( '
			UPDATE T_sessions SET
				sess_data = '.$DB->quote( $sess_data ).',
				sess_ipaddress = "'.$Hit->IP.'",
				sess_key = '.$DB->quote( $this->key ).',
				sess_lastseen = "'.date( 'Y-m-d H:i:s', $localtimenow ).'",
				sess_user_ID = '.$DB->null( $this->user_ID ).'
			WHERE sess_ID = '.$this->ID, 'Session::dbsave()' );

		$Debuglog->add( 'Session data saved!', 'session' );

		$this->_session_needs_save = false;
	}


	/**
	 * Reload session data.
	 *
	 * This is needed if the running process waits for a child process to write data
	 * into the Session, e.g. the captcha plugin in test mode waiting for the Debuglog
	 * output from the process that created the image (included through an IMG tag).
	 */
	function reload_data()
	{
		global $Debuglog, $DB;

		if( empty($this->ID) )
		{
			return false;
		}

		$sess_data = $DB->get_var( '
			SELECT sess_data FROM T_sessions
			 WHERE sess_ID = '.$this->ID );

		$sess_data = @unserialize( $sess_data );
		if( $sess_data === false )
		{
			$this->_data = array();
		}
		else
		{
			$this->_data = $sess_data;
		}

		$Debuglog->add( 'Reloaded session data.' );
	}
}

/*
 * $Log$
 * Revision 1.12  2006/05/29 19:54:45  fplanque
 * no message
 *
 * Revision 1.11  2006/05/12 21:53:37  blueyed
 * Fixes, cleanup, translation for plugins
 *
 * Revision 1.10  2006/05/04 10:18:41  blueyed
 * Added Session property to skip page content caching event.
 *
 * Revision 1.9  2006/05/04 01:06:05  blueyed
 * debuglog
 *
 * Revision 1.8  2006/05/02 22:25:27  blueyed
 * Comment preview for frontoffice.
 *
 * Revision 1.7  2006/04/21 17:05:08  blueyed
 * cleanup
 *
 * Revision 1.6  2006/04/19 20:13:50  fplanque
 * do not restrict to :// (does not catch subdomains, not even www.)
 *
 * Revision 1.5  2006/03/20 18:49:44  fplanque
 * no message
 *
 * Revision 1.4  2006/03/19 19:53:53  blueyed
 * minor
 *
 * Revision 1.3  2006/03/12 23:08:59  fplanque
 * doc cleanup
 *
 * Revision 1.2  2006/03/02 20:05:29  blueyed
 * Fixed/polished stats (linking T_useragents to T_hitlog, not T_sessions again). I've done this the other way around before, but it wasn't my idea.. :p
 *
 * Revision 1.1  2006/02/23 21:11:58  fplanque
 * File reorganization to MVC (Model View Controller) architecture.
 * See index.hml files in folders.
 * (Sorry for all the remaining bugs induced by the reorg... :/)
 *
 * Revision 1.45  2006/01/29 15:07:01  blueyed
 * Added reload_data()
 *
 * Revision 1.43  2006/01/22 19:38:45  blueyed
 * Added expiration support through set() for session data.
 *
 * Revision 1.42  2006/01/20 17:08:13  blueyed
 * Save sess_data as NULL (unserialized) if NULL.
 *
 * Revision 1.41  2006/01/20 16:40:56  blueyed
 * Cleanup
 *
 * Revision 1.40  2006/01/14 14:23:07  blueyed
 * "Out of range" fix in dbsave()
 *
 * Revision 1.39  2006/01/12 21:55:13  blueyed
 * Fix
 *
 * Revision 1.38  2006/01/11 18:23:04  blueyed
 * Also update sess_user_ID in DB on shutdown with set_User() and set_user_ID().
 *
 * Revision 1.36  2006/01/11 01:06:37  blueyed
 * Save session data once at shutdown into DB
 *
 * Revision 1.35  2005/12/21 20:38:18  fplanque
 * Session refactoring/doc
 *
 * Revision 1.34  2005/12/12 19:21:23  fplanque
 * big merge; lots of small mods; hope I didn't make to many mistakes :]
 *
 * Revision 1.33  2005/11/17 19:35:26  fplanque
 * no message
 *
 */
?>