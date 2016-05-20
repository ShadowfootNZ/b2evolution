<?php
/**
 * This file implements the subcontainer Widget class, and it is used to embed a widget container into a widget
 *
 * This file is part of the evoCore framework - {@link http://evocore.net/}
 * See also {@link http://sourceforge.net/projects/evocms/}.
 *
 * @copyright (c)2003-2014 by Francois Planque - {@link http://fplanque.com/}
 *
 * {@internal License choice
 * - If you have received this file as part of a package, please find the license.txt file in
 *   the same folder or the closest folder above for complete license terms.
 * - If you have received this file individually (e-g: from http://evocms.cvs.sourceforge.net/)
 *   then you must choose one of the following licenses before using the file:
 *   - GNU General Public License 2 (GPL) - http://www.opensource.org/licenses/gpl-license.php
 *   - Mozilla Public License 1.1 (MPL) - http://www.opensource.org/licenses/mozilla1.1.php
 * }}
 *
 * @package evocore
 *
 * {@internal Below is a list of authors who have contributed to design/coding of this file: }}
 * @author asimo: Evo Factory / Attila Simo
 *
 * @version $Id: _subcontainer.widget.php 10060 2016-03-09 10:40:31Z yura $
 */
if( !defined('EVO_MAIN_INIT') ) die( 'Please, do not access this page directly.' );

load_class( 'widgets/model/_widget.class.php', 'ComponentWidget' );

/**
 * ComponentWidget Class
 *
 * A ComponentWidget is a displayable entity that can be placed into a Container on a web page.
 *
 * @package evocore
 */
class subcontainer_row_Widget extends ComponentWidget
{
	/**
	 * Constructor
	 */
	function __construct( $db_row = NULL )
	{
		// Call parent constructor:
		parent::__construct( $db_row, 'core', 'subcontainer_row' );
	}


	/**
	 * Get name of widget
	 */
	function get_name()
	{
		$title = T_( 'Sub-container row' );
		return $title;
	}


	/**
	 * Get a very short desc. Used in the widget list.
	 */
	function get_short_desc()
	{
		return format_to_output( $this->disp_params['title'] );
	}


	/**
	 * Get short description
	 */
	function get_desc()
	{
		return T_('Embed any container into a widget. Useful to use widget containers embedded into others.');
	}


	/**
	 * Get definitions for editable params
	 *
	 * @param $params
	 */
	function get_param_definitions( $params )
	{
		global $DB, $Blog;

		$WidgetContainerCache = & get_WidgetContainerCache();
		$container_options = array( '' => T_('None') );
		foreach( $WidgetContainerCache->get_by_coll_ID( $Blog->ID ) as $WidgetContainer )
		{
			$container_options[$WidgetContainer->get( 'code' )] = $WidgetContainer->get( 'name' );
		}

		$widget_params =  array(
			'title' => array(
				'label' => T_('Block title'),
				'size' => 60,
			) );
		for( $i = 1; $i <= 6; $i++ )
		{	// 6 columns for widget containers:
			$widget_params['column'.$i.'_container'] = array(
				'label' => sprintf( T_('Column %d Container'), $i ),
				'note' => T_('The container which will be embedded.'),
				'type' => 'select',
				'options' => $container_options,
				'defaultvalue' => ''
			);
			$widget_params['column'.$i.'_class'] = array(
				'label' => sprintf( T_('Column %d Classes'), $i ),
				'note' => T_('The style classes for container above.'),
				'defaultvalue' => 'col-lg-4 col-md-6 col-sm-6 col-xs-12'
			);
		}

		$r = array_merge( $widget_params, parent::get_param_definitions( $params ) );

		if( isset( $r['allow_blockcache'] ) )
		{	// Disable "allow blockcache":
			$r['allow_blockcache']['defaultvalue'] = false;
			$r['allow_blockcache']['disabled'] = 'disabled';
			$r['allow_blockcache']['note'] = T_('This widget cannot be cached in the block cache.');
		}

		return $r;
	}


	/**
	 * Display the widget!
	 *
	 * @param array MUST contain at least the basic display params
	 */
	function display( $params )
	{
		$this->init_display( $params );

		// START DISPLAY:
		echo $this->disp_params['block_start'];

		// Display title if requested
		$this->disp_title();

		echo $this->disp_params['block_body_start'];

		echo $this->disp_params['rwd_start'];

		for( $i = 1; $i <= 6; $i++ )
		{
			if( empty( $this->disp_params['column'.$i.'_container'] ) )
			{	// Skip column without selected container:
				continue;
			}

			echo str_replace( '$wi_rwd_block_class$', $this->disp_params['column'.$i.'_class'], $this->disp_params['rwd_block_start'] );

			// Display widget container of the column:
			$this->display_column_container( $this->disp_params['column'.$i.'_container'], $params );

			echo $this->disp_params['rwd_block_end'];
		}

		echo $this->disp_params['rwd_end'];

		echo $this->disp_params['block_body_end'];

		echo $this->disp_params['block_end'];

		return true;
	}


	/**
	 * Display widget container of one column
	 *
	 * @param string Sub-container code
	 * @param array Params
	 */
	function display_column_container( $subcontainer_code, $params )
	{
		global $Blog, $Timer, $displayed_subcontainers;

		if( ! isset( $displayed_subcontainers ) )
		{	// Initialize the dispalyed subcontainers array at first usage:
			// Use this array to avoid embedded containers display in infinite loop
			$displayed_subcontainers = array();
		}
		elseif( in_array( $subcontainer_code, $displayed_subcontainers ) )
		{	// Do not try do display the same subcontainer which were already displayed to avoid infinite display:
			$WidgetContainerCache = & get_WidgetContainerCache();
			if( $WidgetContainer = & $WidgetContainerCache->get_by_coll_and_code( $Blog->ID, $subcontainer_code ) )
			{
				$subcontainer_name = $WidgetContainer->get( 'name' );
			}
			else
			{
				$subcontainer_name = $subcontainer_code;
			}
			echo '<div class="alert alert-danger">'.sprintf( T_('Cannot include container "%s" because it would create an infinite loop.'), $subcontainer_name ).'</div>';
			return;
		}

		// Add this subcontainer to the displayed_containers array:
		$displayed_subcontainers[] = $subcontainer_code;

		// Get enabled widgets of the container:
		$EnabledWidgetCache = & get_EnabledWidgetCache();
		$container_widgets = & $EnabledWidgetCache->get_by_coll_container( $Blog->ID, $subcontainer_code, true );

		if( ! empty( $container_widgets ) )
		{
			foreach( $container_widgets as $ComponentWidget )
			{	// Let the Widget display itself (with contextual params):
				$widget_timer_name = 'Widget->display('.$ComponentWidget->code.')';
				$Timer->start( $widget_timer_name );
				$ComponentWidget->display_with_cache( $params );
				$Timer->pause( $widget_timer_name );
			}
		}

		// Remove the last item which must be this container from the end of the displayed containers:
		array_pop( $displayed_subcontainers );
	}
}

?>