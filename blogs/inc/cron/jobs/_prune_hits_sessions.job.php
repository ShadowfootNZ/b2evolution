<?php
/**
 * This file implements the Hit and Session pruning Cron controller
 *
 * @author fplanque: Francois PLANQUE
 *
 * @version $Id$
 */
if( !defined('EVO_MAIN_INIT') ) die( 'Please, do not access this page directly.' );

global $Settings;

if( $Settings->get( 'auto_prune_stats_mode' ) != 'cron' )
{ // Autopruning is NOT requested
	$result_message = T_('Auto pruning is not set to run as a scheduled task');
	return 2;
}

load_class( 'sessions/model/_hitlist.class.php', 'Hitlist' );

$result_message = Hitlist::dbprune(); // will prune once per day, according to Settings

if( empty($result_message) )
{
	return 1; /* ok */
}

return 100;

/*
 * $Log$
 * Revision 1.7  2011/09/10 02:09:09  fplanque
 * doc
 *
 * Revision 1.6  2011/09/09 23:05:08  lxndral
 * Search for "fp>al" in code to find my comments and please make requested changed
 *
 * Revision 1.5  2011/09/09 21:53:55  fplanque
 * doc
 *
 * Revision 1.4  2011/09/08 17:59:59  lxndral
 * Prune for internal searches
 *
 * Revision 1.3  2009/09/14 12:53:16  efy-arrin
 * Included the ClassName in load_class() call with proper UpperCase
 *
 * Revision 1.2  2009/09/14 11:27:40  efy-arrin
 * Included the ClassName in load_class() call with proper UpperCase
 *
 * Revision 1.1  2007/06/25 10:59:46  fplanque
 * MODULES (refactored MVC)
 *
 * Revision 1.1  2006/07/06 19:59:08  fplanque
 * better logs, better stats, better pruning
 *
 */
?>